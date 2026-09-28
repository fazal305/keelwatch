"""Dependency changes between the base and head versions of manifests
(package.json, composer.json, requirements*.txt)."""

from __future__ import annotations

import json
import re
from dataclasses import dataclass
from typing import Any

from .findings import make_finding

PHASE = "dependencies"

NPM_SECTIONS = ("dependencies", "devDependencies", "optionalDependencies", "peerDependencies")
COMPOSER_SECTIONS = ("require", "require-dev")
_SEMVER = re.compile(r"v?(\d+)\.(\d+)\.(\d+)(?:[-+][0-9A-Za-z.-]+)?")
_REQ_LINE = re.compile(r"^([A-Za-z0-9][A-Za-z0-9._-]*)(\[[^\]]*\])?\s*(.*)$")


class ManifestError(ValueError):
    pass


@dataclass(frozen=True)
class Change:
    ecosystem: str
    section: str
    name: str
    before: str | None
    after: str | None

    @property
    def kind(self) -> str:
        if self.before is None:
            return "added"
        if self.after is None:
            return "removed"
        return "changed"


def normalise_pypi(name: str) -> str:
    return re.sub(r"[-_.]+", "-", name).lower()


def parse(ecosystem: str, text: str | None) -> dict[str, dict[str, str]]:
    """Returns {section: {package: spec}}. Missing file -> empty manifest."""
    if text is None:
        return {}
    if ecosystem == "PyPI":
        return {"requirements": _parse_requirements(text)}
    try:
        data = json.loads(text)
    except json.JSONDecodeError as exc:
        raise ManifestError(f"invalid JSON: {exc.msg}") from exc
    if not isinstance(data, dict):
        raise ManifestError("manifest is not a JSON object")
    sections = NPM_SECTIONS if ecosystem == "npm" else COMPOSER_SECTIONS
    out: dict[str, dict[str, str]] = {}
    for section in sections:
        deps = data.get(section)
        if isinstance(deps, dict):
            out[section] = {
                str(k): str(v)
                for k, v in deps.items()
                # Composer lists the PHP runtime and extensions as requirements.
                if not (ecosystem == "Packagist" and (k == "php" or str(k).startswith("ext-")))
            }
    return out


def _parse_requirements(text: str) -> dict[str, str]:
    out: dict[str, str] = {}
    for raw in text.splitlines():
        line = raw.split(" #", 1)[0].strip()
        if not line or line.startswith(("#", "-r", "-c", "--")):
            continue
        if line.startswith(("-e ", "git+", "http://", "https://")):
            # An editable or URL install; key it by the URL itself.
            out[line] = line
            continue
        if " @ " in line:
            name, url = line.split(" @ ", 1)
            out[normalise_pypi(name.strip())] = "@ " + url.strip()
            continue
        match = _REQ_LINE.match(line.split(";", 1)[0].strip())
        if match:
            out[normalise_pypi(match.group(1))] = match.group(3).strip()
    return out


def diff(ecosystem: str, before: dict, after: dict) -> list[Change]:
    changes = []
    for section in sorted(set(before) | set(after)):
        old, new = before.get(section, {}), after.get(section, {})
        for name in sorted(set(old) | set(new)):
            if old.get(name) != new.get(name):
                changes.append(Change(ecosystem, section, name, old.get(name), new.get(name)))
    return changes


def exact_version(ecosystem: str, spec: str | None) -> str | None:
    if not spec:
        return None
    spec = spec.strip()
    if ecosystem == "PyPI":
        if spec.startswith("==") and "*" not in spec and "," not in spec:
            candidate = spec[2:].strip()
            return candidate if re.fullmatch(r"[0-9][0-9A-Za-z.+!-]*", candidate) else None
        return None
    match = _SEMVER.fullmatch(spec)
    return spec.lstrip("v") if match else None


def is_url_source(ecosystem: str, spec: str) -> bool:
    s = spec.strip().lower()
    if ecosystem == "PyPI":
        return s.startswith(("git+", "http://", "https://", "-e ", "@ "))
    if s.startswith(
        ("git+", "git:", "git@", "github:", "gitlab:", "bitbucket:", "http:", "https:", "file:")
    ):
        return True
    # npm "user/repo" shorthand fetches straight from GitHub.
    return ecosystem == "npm" and bool(re.fullmatch(r"[\w.-]+/[\w.-]+(#.*)?", s))


def is_unpinned(ecosystem: str, spec: str) -> bool:
    s = spec.strip().lower()
    if s in ("", "*", "latest", "x", "x.x.x"):
        return True
    if ecosystem == "PyPI":
        return not s or (s.startswith(">") and "<" not in s)
    return s.startswith(">") and "<" not in s


def _version_tuple(version: str) -> tuple[int, ...]:
    return tuple(int(p) for p in re.findall(r"\d+", version)[:3])


def rules_for(change: Change, file_path: str) -> list[dict[str, Any]]:
    if change.after is None:
        return []
    findings = []
    evidence = {
        "kind": "dependency",
        "package": change.name[:214],
        "ecosystem": change.ecosystem,
        "version_before": change.before[:100] if change.before else None,
        "version_after": change.after[:100],
    }
    common = {
        "phase": PHASE,
        "category": "dependency",
        "source": "rule",
        "file_path": file_path,
        "evidence": evidence,
    }

    if is_url_source(change.ecosystem, change.after):
        findings.append(
            make_finding(
                **common,
                severity="medium",
                confidence="high",
                title=f"{change.name} is installed from a URL or git source",
                description="Code from a URL or git reference bypasses the registry's versioning and review.",
                rule_id="deps.url-source",
                recommendation="Depend on a published registry release, or pin an exact commit if unavoidable.",
                identity=change.name,
            )
        )
    elif change.ecosystem == "Packagist" and change.after.strip().lower().startswith("dev-"):
        findings.append(
            make_finding(
                **common,
                severity="low",
                confidence="high",
                title=f"{change.name} tracks a development branch",
                description=f"'{change.after}' follows a moving branch rather than a release.",
                rule_id="deps.branch-dependency",
                recommendation="Require a tagged release.",
                identity=change.name,
            )
        )
    elif is_unpinned(change.ecosystem, change.after):
        findings.append(
            make_finding(
                **common,
                severity="low",
                confidence="high",
                title=f"{change.name} has no upper version bound",
                description=f"'{change.after or '(any)'}' accepts any future version, including breaking ones.",
                rule_id="deps.unpinned",
                recommendation="Use a bounded range or an exact version with a lockfile.",
                identity=change.name,
            )
        )

    old_v, new_v = (
        exact_version(change.ecosystem, change.before),
        exact_version(change.ecosystem, change.after),
    )
    if old_v and new_v and _version_tuple(new_v) < _version_tuple(old_v):
        findings.append(
            make_finding(
                **common,
                severity="low",
                confidence="medium",
                title=f"{change.name} was downgraded",
                description=f"{old_v} -> {new_v}. Downgrades can reintroduce fixed bugs or vulnerabilities.",
                rule_id="deps.downgrade",
                recommendation="Confirm the downgrade is intentional and note why.",
                identity=change.name,
            )
        )
    return findings


def vulnerability_finding(
    change: Change, version: str, advisories: list[str], file_path: str
) -> dict[str, Any]:
    return make_finding(
        phase=PHASE,
        severity="high",
        category="dependency",
        confidence="high",
        title=f"{change.name} {version} has known vulnerabilities",
        description=(
            f"OSV.dev lists {len(advisories)} advisory record(s) affecting {change.name} {version}. "
            "Severity per advisory is not assessed here; check each record."
        ),
        source="osv",
        evidence={
            "kind": "dependency",
            "package": change.name[:214],
            "ecosystem": change.ecosystem,
            "version_before": change.before[:100] if change.before else None,
            "version_after": change.after[:100] if change.after else None,
            "advisory_ids": advisories[:50],
        },
        file_path=file_path,
        recommendation="Upgrade to a version the advisories list as fixed.",
        identity=f"{change.name}@{version}",
    )
