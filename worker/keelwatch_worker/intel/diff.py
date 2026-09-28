"""Unified-diff helpers and file classification."""

from __future__ import annotations

import re
from collections.abc import Iterator
from pathlib import PurePosixPath

_HUNK = re.compile(r"^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@")

LOCKFILES = frozenset(
    {
        "package-lock.json",
        "npm-shrinkwrap.json",
        "yarn.lock",
        "pnpm-lock.yaml",
        "bun.lockb",
        "composer.lock",
        "poetry.lock",
        "pipfile.lock",
        "uv.lock",
        "cargo.lock",
        "go.sum",
        "gemfile.lock",
    }
)
_GENERATED_PARTS = frozenset(
    {"dist", "build", "vendor", "node_modules", "__snapshots__", ".next", "coverage"}
)
_GENERATED_SUFFIXES = (".min.js", ".min.css", ".map", ".snap", ".pb.go", "_pb2.py", ".lock")
_TEST_PARTS = frozenset({"test", "tests", "__tests__", "spec", "specs", "e2e"})
_TEST_NAME = re.compile(
    r"(?:^test_.*\.py$|_test\.(?:py|go)$|\.(?:test|spec)\.[cm]?[jt]sx?$|Test\.php$)"
)
SOURCE_EXTENSIONS = frozenset(
    {
        ".py",
        ".js",
        ".jsx",
        ".mjs",
        ".cjs",
        ".ts",
        ".tsx",
        ".php",
        ".go",
        ".rb",
        ".java",
        ".kt",
        ".cs",
        ".rs",
        ".vue",
        ".svelte",
        ".swift",
        ".scala",
        ".c",
        ".cc",
        ".cpp",
        ".h",
    }
)
MANIFESTS = {"package.json": "npm", "composer.json": "Packagist"}


def added_lines(patch: str | None) -> Iterator[tuple[int, str]]:
    """Yields (line number in the new file, text) for every added line."""
    if not patch:
        return
    line_no: int | None = None
    for raw in patch.splitlines():
        match = _HUNK.match(raw)
        if match:
            line_no = int(match.group(1))
            continue
        if line_no is None or raw.startswith("\\"):
            continue  # before the first hunk, or "\ No newline at end of file"
        if raw.startswith("+"):
            yield line_no, raw[1:]
            line_no += 1
        elif raw.startswith("-"):
            continue
        else:
            line_no += 1


def _parts(path: str) -> tuple[str, ...]:
    return PurePosixPath(path).parts


def is_lockfile(path: str) -> bool:
    return PurePosixPath(path).name.lower() in LOCKFILES


def is_generated(path: str) -> bool:
    lowered = path.lower()
    return (
        is_lockfile(path)
        or any(part in _GENERATED_PARTS for part in _parts(lowered))
        or lowered.endswith(_GENERATED_SUFFIXES)
    )


def is_test(path: str) -> bool:
    name = PurePosixPath(path).name
    return any(part.lower() in _TEST_PARTS for part in _parts(path)[:-1]) or bool(
        _TEST_NAME.search(name)
    )


def is_source(path: str) -> bool:
    return PurePosixPath(path).suffix.lower() in SOURCE_EXTENSIONS and not is_generated(path)


def manifest_ecosystem(path: str) -> str | None:
    name = PurePosixPath(path).name
    if name in MANIFESTS:
        return MANIFESTS[name]
    if re.fullmatch(r"requirements(?:[-_.][\w.-]+)?\.txt", name):
        return "PyPI"
    return None


def top_level_area(path: str) -> str:
    parts = _parts(path)
    return parts[0] if len(parts) > 1 else "(root)"
