"""Contract tests: the same fixtures as api/tests/Contract/ContractTest.php.

Valid fixtures must pass; invalid fixtures (one-change patches on a valid
fixture) must fail. If PHP and Python ever disagree, one of the two suites
fails.
"""

import copy
import json
import re
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator, ValidationError
from jsonschema.validators import extend

CONTRACTS_DIR = Path(__file__).resolve().parents[2] / "contracts"
CONTRACTS = ("github-event", "analysis-job", "finding")


def ecma_regex(pattern: str) -> str:
    """Rewrite anchor `$` (outside character classes, unescaped) to `\\Z`.

    JSON Schema specifies ECMA-262 regexes, where `$` is the true end of the
    string. Python's `$` also matches before a trailing newline.
    """
    out, in_class, escaped = [], False, False
    for ch in pattern:
        if escaped:
            out.append(ch)
            escaped = False
        elif ch == "\\":
            out.append(ch)
            escaped = True
        elif ch == "[":
            in_class = True
            out.append(ch)
        elif ch == "]":
            in_class = False
            out.append(ch)
        elif ch == "$" and not in_class:
            out.append(r"\Z")
        else:
            out.append(ch)
    return "".join(out)


def _pattern(validator, pattern, instance, schema):
    if validator.is_type(instance, "string") and not re.search(ecma_regex(pattern), instance):
        yield ValidationError(f"{instance!r} does not match {pattern!r}")


EcmaValidator = extend(Draft202012Validator, {"pattern": _pattern})


def load(path: Path):
    return json.loads(path.read_text(encoding="utf-8"))


def validator_for(contract: str):
    schema = load(CONTRACTS_DIR / f"{contract}.v1.json")
    Draft202012Validator.check_schema(schema)
    return EcmaValidator(schema)


def fixtures(kind: str):
    return [
        pytest.param(contract, path, id=f"{contract}/{path.name}")
        for contract in CONTRACTS
        for path in sorted((CONTRACTS_DIR / "fixtures" / contract / kind).glob("*.json"))
    ]


def apply_patch(document: dict, patch: dict) -> dict:
    doc = copy.deepcopy(document)
    changes = 0

    for dotted, value in patch.get("set", {}).items():
        *parents, leaf = dotted.split(".")
        target = doc
        for segment in parents:
            assert isinstance(target.get(segment), dict), f"patch path {dotted} missing in base"
            target = target[segment]
        assert leaf not in target or target[leaf] != value, f"patch on {dotted} is a no-op"
        target[leaf] = value
        changes += 1

    for dotted in patch.get("unset", []):
        *parents, leaf = dotted.split(".")
        target = doc
        for segment in parents:
            target = target[segment]
        assert leaf in target, f"unset path {dotted} missing in base"
        del target[leaf]
        changes += 1

    assert changes == 1, "each invalid fixture must make exactly one change"
    return doc


def test_ecma_regex_rewrites_only_anchors():
    assert ecma_regex(r"^[0-9a-f]{40}$") == r"^[0-9a-f]{40}\Z"
    assert ecma_regex(r"^a([/\\]|$)") == r"^a([/\\]|\Z)"
    assert ecma_regex(r"^[$]\$$") == r"^[$]\$\Z"


def test_every_contract_has_fixtures():
    for contract in CONTRACTS:
        base = CONTRACTS_DIR / "fixtures" / contract
        assert list((base / "valid").glob("*.json")), f"{contract}: no valid fixtures"
        assert list((base / "invalid").glob("*.json")), f"{contract}: no invalid fixtures"


@pytest.mark.parametrize(("contract", "path"), fixtures("valid"))
def test_valid_fixture_passes(contract, path):
    errors = [e.message for e in validator_for(contract).iter_errors(load(path))]
    assert errors == []


@pytest.mark.parametrize(("contract", "path"), fixtures("invalid"))
def test_invalid_fixture_fails(contract, path):
    patch = load(path)
    base = load(CONTRACTS_DIR / "fixtures" / contract / "valid" / patch["base"])
    document = apply_patch(base, patch)
    assert not validator_for(contract).is_valid(document), f"{path.name} should be rejected"
