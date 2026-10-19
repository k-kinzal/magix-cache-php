"""Check required candidates/structure, independently of Magix's existing reader.

Usage: python3 this-file.py expectations.json raw-results.json
The sole retained-call projection is the metadata minimum of concrete integers.
There is no PHP control-flow execution or arbitrary function evaluation here.
"""

import itertools
import json
import sys


def key(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":"))


def compatible(left, right):
    return all(name not in right or right[name] == value
               for name, value in left.items())


def expand(nodes, index):
    node = nodes[index]
    kind = node["kind"]
    if kind == "constant":
        return [(node["literal"], {})]
    if kind == "choice":
        return [row for child in node["operands"] for row in expand(nodes, child)]
    if kind == "alternative":
        guards = dict(node["attributes"]) if node["attributes"] else {}
        return [(value, {**selection, **guards})
                for value, selection in expand(nodes, node["operands"][0])
                if compatible(selection, guards)]
    raise ValueError(f"Cannot project {kind} as a concrete metadata operand")


def retained_min(term):
    nodes = term["nodes"]
    root = nodes[term["root"]]
    if root["kind"] != "call" or root["literal"] != "min":
        raise ValueError("Expected a retained min call")
    left, right = [expand(nodes, child) for child in root["operands"]]
    pairs = [(a, b) for (a, x), (b, y) in itertools.product(left, right)
             if compatible(x, y)]
    if not all(type(a) is int and type(b) is int for a, b in pairs):
        raise ValueError("This metadata projection only supports integer pairs")
    return sorted(set(min(a, b) for a, b in pairs)), sorted(set(pairs))


def matches(nodes, index, pattern):
    node = nodes[index]
    for field, expected in pattern.items():
        actual = node.get(field)
        if field == "operands":
            if isinstance(expected, list):
                if not isinstance(actual, list) or len(actual) != len(expected):
                    return False
                pairs = zip(actual, expected)
            else:
                if not isinstance(actual, dict) or not expected.keys() <= actual.keys():
                    return False
                pairs = [(actual[name], item) for name, item in expected.items()]
            if not all(matches(nodes, child, item) for child, item in pairs):
                return False
        elif field == "attributes":
            if not isinstance(actual, dict) or any(actual.get(k) != v for k, v in expected.items()):
                return False
        elif actual != expected:
            return False
    return True


def verify(manifest, raw):
    results = {case["id"]: case for case in raw["cases"]}
    checked = []
    for case in manifest["cases"]:
        expected = case["expect"]
        result = results[case["id"]]["structural"]
        values = [c["result"] for c in result["candidates"] if c["type"] == "analyzed"]
        partials = [c["result"] for c in result["candidates"] if c["type"] == "partials"]
        nodes = [node for partial in partials for node in partial["nodes"]]
        constants = {key(node["literal"]) for node in nodes if node["kind"] == "constant"}
        reasons = {node["attributes"]["reason"] for node in nodes
                   if isinstance(node["attributes"], dict) and "reason" in node["attributes"]}
        kinds = {node["kind"] for node in nodes}
        checks = {"notInterrupted": not result["interrupted"]}
        projected, pairs = [], []
        if expected.get("allowedRetainedOperation") == "min":
            for partial in partials:
                derived, arguments = retained_min(partial)
                projected.extend(derived)
                pairs.extend(arguments)
        if "values" in expected:
            checks["requiredCandidateValues"] = (
                {key(v) for v in values + projected} == {key(v) for v in expected["values"]}
                and (not partials or expected.get("allowedRetainedOperation") == "min")
            )
        if expected.get("requiredValues"):
            checks["knownAlternativesRetained"] = (
                {key(v) for v in expected["requiredValues"]} <= {key(v) for v in values}
            )
        if "knownValues" in expected:
            checks["noInventedConcreteValues"] = (
                {key(v) for v in values} == {key(v) for v in expected["knownValues"]}
            )
        if expected.get("partialRoot"):
            checks["partialFieldDependencies"] = bool(partials) and all(
                matches(term["nodes"], term["root"], expected["partialRoot"])
                for term in partials
            )
        if partials and expected.get("retainedArgumentPairs"):
            checks["retainedOperandCorrelation"] = (
                {tuple(pair) for pair in pairs}
                == {tuple(pair) for pair in expected["retainedArgumentPairs"]}
            )
        if expected.get("reasons"):
            checks["dynamicDependenciesRetained"] = set(expected["reasons"]) <= reasons
        if expected.get("requirePartial"):
            checks["unresolvedAlternativeRetained"] = bool(partials)
        if expected.get("constants"):
            checks["knownPartialOperandsRetained"] = {key(v) for v in expected["constants"]} <= constants
        if expected.get("kinds"):
            checks["partialOperationsRetained"] = set(expected["kinds"]) <= kinds
        checked.append({
            "id": case["id"], "passed": all(checks.values()), "checks": checks,
            "concreteValues": values, "partialReasons": sorted(reasons),
            "partialKinds": sorted(kinds), "consumerProjectedValues": projected,
            "retainedArgumentPairs": pairs,
        })
    return checked


if __name__ == "__main__":
    with open(sys.argv[1]) as source:
        manifest = json.load(source)
    with open(sys.argv[2]) as source:
        raw = json.load(source)
    results = verify(manifest, raw)
    print(json.dumps(results, indent=2))
    sys.exit(0 if all(case["passed"] for case in results) else 1)
