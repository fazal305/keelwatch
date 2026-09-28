import json
from pathlib import Path

import pytest
from test_contracts import EcmaValidator

from keelwatch_worker.intel import deps, structure
from keelwatch_worker.intel.diff import (
    added_lines,
    is_generated,
    is_lockfile,
    is_source,
    is_test,
    manifest_ecosystem,
)
from keelwatch_worker.intel.findings import fingerprint, make_finding
from keelwatch_worker.intel.secrets import scan

CONTRACTS = Path(__file__).resolve().parents[2] / "contracts"
FINDING_SCHEMA = json.loads((CONTRACTS / "finding.v1.json").read_text(encoding="utf-8"))


def assert_contract(findings):
    validator = EcmaValidator(FINDING_SCHEMA)
    for f in findings:
        errors = [e.message for e in validator.iter_errors(f)]
        assert errors == [], (f["rule_id"], errors)


def secret(prefix: str, char: str, length: int) -> str:
    """Credential-shaped test values are assembled at runtime."""
    return prefix + char * length


def patch(*lines: str, start: int = 1) -> str:
    return f"@@ -0,0 +{start},{len(lines)} @@\n" + "\n".join("+" + line for line in lines)


def file(path, *lines, status="modified", start=1):
    return {
        "path": path,
        "status": status,
        "additions": len(lines),
        "deletions": 0,
        "patch": patch(*lines, start=start) if lines else None,
    }


# ----- diff parsing and classification -------------------------------------------------


def test_added_lines_track_new_file_line_numbers_across_hunks():
    p = (
        "@@ -1,3 +1,4 @@\n"
        " unchanged\n"
        "-removed\n"
        "+added at 2\n"
        "+added at 3\n"
        " context at 4\n"
        "@@ -20,2 +21,2 @@\n"
        " context at 21\n"
        "+added at 22\n"
        "\\ No newline at end of file"
    )
    assert list(added_lines(p)) == [(2, "added at 2"), (3, "added at 3"), (22, "added at 22")]
    assert list(added_lines(None)) == []


@pytest.mark.parametrize(
    ("path", "lock", "generated", "test", "source"),
    [
        ("package-lock.json", True, True, False, False),
        ("web/dist/app.min.js", False, True, False, False),
        ("src/app.js", False, False, False, True),
        ("src/app.test.js", False, False, True, True),
        ("tests/test_api.py", False, False, True, True),
        ("api/tests/Unit/ConfigTest.php", False, False, True, True),
        ("worker/keelwatch_worker/queue.py", False, False, False, True),
        ("vendor/lib/x.php", False, True, False, False),
        ("README.md", False, False, False, False),
    ],
)
def test_file_classification(path, lock, generated, test, source):
    assert (is_lockfile(path), is_generated(path), is_test(path), is_source(path)) == (
        lock,
        generated,
        test,
        source,
    )


def test_manifest_detection():
    assert manifest_ecosystem("web/package.json") == "npm"
    assert manifest_ecosystem("api/composer.json") == "Packagist"
    assert manifest_ecosystem("requirements-dev.txt") == "PyPI"
    assert manifest_ecosystem("worker/requirements.txt") == "PyPI"
    assert manifest_ecosystem("package-lock.json") is None
    assert manifest_ecosystem("docs/requirements.md") is None


# ----- secrets ------------------------------------------------------------------------


def test_committed_credential_is_reported_with_line_and_never_stored():
    token = secret("gh" + "p_", "A", 36)
    findings = scan([file("src/client.js", "const a = 1;", f"const token = '{token}';", start=10)])

    [f] = findings
    assert f["rule_id"] == "secrets.github-token"
    assert f["severity"] == "critical"
    assert f["location"] == {"file_path": "src/client.js", "line_start": 11, "line_end": 11}
    assert token not in json.dumps(f), "the finding must not contain the secret itself"
    assert "[REDACTED:github_token]" in f["evidence"]["snippet"]
    assert_contract(findings)


def test_secrets_in_tests_are_reported_at_lower_severity():
    [f] = scan([file("tests/test_client.py", f"KEY = '{secret('AK' + 'IA', 'Q', 16)}'")])
    assert f["severity"] == "medium"


def test_assigned_secrets_and_placeholders():
    findings = scan(
        [
            file(
                "config/settings.py",
                'db_password = "Sup3r-S3cret-Value"',
                'api_key = os.environ["API_KEY"]',
                'password = "changeme"',
            )
        ]
    )
    assert [(f["rule_id"], f["location"]["line_start"]) for f in findings] == [
        ("secrets.assigned-secret", 1)
    ]
    assert "Sup3r-S3cret-Value" not in json.dumps(findings)


def test_sensitive_files_are_flagged_but_examples_are_not():
    findings = scan(
        [
            file(".env", status="added"),
            file("deploy/id_rsa", status="added"),
            file(".env.example", status="added"),
            file("certs/server.pem", status="removed"),
        ]
    )
    assert sorted(f["location"]["file_path"] for f in findings) == [".env", "deploy/id_rsa"]
    assert {f["rule_id"] for f in findings} == {"secrets.sensitive-file"}


def test_insecure_patterns_respect_file_types():
    findings = scan(
        [
            file("src/api.py", "requests.get(url, verify=False)"),
            file("src/View.jsx", "<div dangerouslySetInnerHTML={{__html: body}} />"),
            file("notes/security.py", "# we never use dangerouslySetInnerHTML here"),
        ]
    )
    assert sorted(f["rule_id"] for f in findings) == [
        "config.tls-verification-disabled",
        "web.raw-html-sink",
    ]
    assert_contract(findings)


def test_only_added_lines_and_non_generated_files_are_scanned():
    token = secret("gs" + "k_", "z", 40)
    removed_only = {
        "path": "src/a.js",
        "status": "modified",
        "additions": 0,
        "deletions": 1,
        "patch": f"@@ -1,1 +0,0 @@\n-const k = '{token}';",
    }
    generated = file("dist/bundle.min.js", f"var k='{token}'")
    assert scan([removed_only, generated]) == []


# ----- dependencies --------------------------------------------------------------------


def test_npm_manifest_diff_and_rules():
    before = deps.parse(
        "npm", json.dumps({"dependencies": {"lodash": "4.17.21", "left": "^1.0.0"}})
    )
    after = deps.parse(
        "npm",
        json.dumps(
            {
                "dependencies": {
                    "lodash": "4.17.15",
                    "left": "^1.0.0",
                    "evil": "github:someone/evil",
                },
                "devDependencies": {"anything": "*"},
            }
        ),
    )
    changes = deps.diff("npm", before, after)
    assert [(c.section, c.name, c.kind) for c in changes] == [
        ("dependencies", "evil", "added"),
        ("dependencies", "lodash", "changed"),
        ("devDependencies", "anything", "added"),
    ]
    findings = [f for c in changes for f in deps.rules_for(c, "package.json")]
    assert sorted(f["rule_id"] for f in findings) == [
        "deps.downgrade",
        "deps.unpinned",
        "deps.url-source",
    ]
    assert_contract(findings)


def test_composer_ignores_platform_requirements_and_flags_dev_branches():
    after = deps.parse(
        "Packagist",
        json.dumps({"require": {"php": ">=8.3", "ext-json": "*", "acme/lib": "dev-main"}}),
    )
    assert after == {"require": {"acme/lib": "dev-main"}}
    [change] = deps.diff("Packagist", {}, after)
    assert [f["rule_id"] for f in deps.rules_for(change, "composer.json")] == [
        "deps.branch-dependency"
    ]


def test_requirements_parsing():
    text = (
        "# comment\nDjango==5.1.2\nrequests>=2.0\nPyYAML\n"
        "-r base.txt\nmylib @ https://example.com/mylib.tar.gz\ngit+https://example.com/x.git\n"
        "Flask_Login==0.6.3 ; python_version > '3.8'\n"
    )
    parsed = deps.parse("PyPI", text)["requirements"]
    assert parsed["django"] == "==5.1.2"
    assert parsed["requests"] == ">=2.0"
    assert parsed["pyyaml"] == ""
    assert parsed["flask-login"] == "==0.6.3"
    assert parsed["mylib"].startswith("@ ")
    assert deps.exact_version("PyPI", "==5.1.2") == "5.1.2"
    assert deps.exact_version("PyPI", ">=2.0") is None
    assert deps.exact_version("npm", "^1.2.3") is None
    assert deps.exact_version("npm", "1.2.3") == "1.2.3"


def test_invalid_manifest_is_reported_not_raised_blindly():
    with pytest.raises(deps.ManifestError):
        deps.parse("npm", "{not json")


def test_vulnerability_finding_satisfies_the_contract():
    change = deps.Change("npm", "dependencies", "lodash", "4.17.21", "4.17.15")
    f = deps.vulnerability_finding(
        change, "4.17.15", ["GHSA-29mw-wpgm-hmr9", "CVE-2020-8203"], "package.json"
    )
    assert f["source"] == "osv"
    assert f["evidence"]["advisory_ids"] == ["GHSA-29mw-wpgm-hmr9", "CVE-2020-8203"]
    assert_contract([f])


# ----- structure ----------------------------------------------------------------------


def test_structure_metrics_exclude_generated_files():
    files = [
        {"path": "src/a.py", "status": "modified", "additions": 60, "deletions": 5},
        {"path": "package-lock.json", "status": "modified", "additions": 5000, "deletions": 4000},
    ]
    m = structure.metrics(files)
    assert (m["lines_added"], m["lines_deleted"], m["files_generated_or_lockfile"]) == (60, 5, 1)
    assert [f["rule_id"] for f in structure.findings(m)] == ["structure.no-test-changes"]


def test_large_change_is_flagged_as_a_heuristic():
    files = [
        {"path": f"src/m{i}.py", "status": "added", "additions": 200, "deletions": 0}
        for i in range(5)
    ]
    files.append({"path": "tests/test_m.py", "status": "added", "additions": 10, "deletions": 0})
    findings = structure.findings(structure.metrics(files))
    assert [f["rule_id"] for f in findings] == ["structure.large-change"]
    assert "heuristic" in findings[0]["description"]
    assert_contract(findings)


# ----- findings -------------------------------------------------------------------------


def test_fingerprint_ignores_line_moves_but_not_content():
    a = make_finding(
        phase="secrets",
        severity="high",
        category="security",
        confidence="high",
        title="t",
        description="d",
        source="rule",
        rule_id="r.x",
        file_path="a.py",
        line=3,
        identity="x=1",
    )
    b = make_finding(
        phase="secrets",
        severity="high",
        category="security",
        confidence="high",
        title="t",
        description="d",
        source="rule",
        rule_id="r.x",
        file_path="a.py",
        line=40,
        identity="x=1",
    )
    c = make_finding(
        phase="secrets",
        severity="high",
        category="security",
        confidence="high",
        title="t",
        description="d",
        source="rule",
        rule_id="r.x",
        file_path="b.py",
        line=3,
        identity="x=1",
    )
    assert a["fingerprint"] == b["fingerprint"] != c["fingerprint"]
    assert fingerprint("A  b") == fingerprint("a b")


def test_make_finding_rejects_bad_provenance():
    with pytest.raises(ValueError):
        make_finding(
            phase="p",
            severity="high",
            category="security",
            confidence="high",
            title="t",
            description="d",
            source="rule",
        )
    with pytest.raises(ValueError):
        make_finding(
            phase="p",
            severity="urgent",
            category="security",
            confidence="high",
            title="t",
            description="d",
            source="osv",
        )
