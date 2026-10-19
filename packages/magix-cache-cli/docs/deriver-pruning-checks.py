"""Verify the standalone Deriver probe output supplied as the first argument."""

import json
import sys


def values(result):
    return sorted(
        [candidate["result"] for candidate in result["candidates"]
         if candidate["type"] == "analyzed"],
        key=repr,
    )


def reasons(result):
    return {
        node.get("attributes", {}).get("reason")
        for candidate in result["candidates"]
        if candidate["type"] == "partials"
        for node in candidate["result"]["nodes"]
        if isinstance(node.get("attributes"), dict)
    }


def verify(report):
    checks = []

    def check(name, condition):
        checks.append({"name": name, "passed": condition})

    for mode in ["structural", "pruned"]:
        expected_cases = {
            "finally-followed-by-return": [30],
            "catch-statement": [60],
            "catch-expression": [60],
            "catch-arithmetic": [60],
            "branch-correlation": [[30, "a"], [60, "b"]],
            "alias-correlation": [0],
            "dynamic-branch": [30, 60],
            "unknown-boolean-with-known-alternative": [30, 60],
        }
        for name, expected in expected_cases.items():
            check(f"{mode}:{name}", values(report["cases"][name][mode])
                  == sorted(expected, key=repr))

        thrown = report["cases"]["only-throw"][mode]
        check(f"{mode}:throw has no concrete null",
              values(thrown) == [] and "NO_VALUE_DEFINITION" in reasons(thrown))
        for name in ["dynamic-array", "dynamic-addition", "input-default",
                     "request-input", "environment-input"]:
            result = report["cases"][name][mode]
            check(f"{mode}:{name} stays dynamic",
                  values(result) == [] and "EXTERNAL_INPUT" in reasons(result))

        queries = {query["query"]: query for query in report["paths"][mode]}
        expected_paths = {
            "page -> bridge -> ttl#return": [30] if mode == "pruned" else [30, 60],
            "other -> ... -> ttl#return": [60] if mode == "pruned" else [30, 60],
            "page -> ttl#return": [],
            "unrelated -> constantTtl#return": [],
            "hidden -> constantTtl#return": [] if mode == "pruned" else [7],
            "pairs -> pair#return": ["ab", "cd"],
            "Endpoint::show -> CacheQuery::execute#return": [[30, "product"]],
            "Endpoint::preview -> CacheQuery::execute#return": [[60, "draft"]],
            "\\endpoint::SHOW -> \\CacheQuery::EXECUTE#return": [[30, "product"]],
        }
        for query, expected in expected_paths.items():
            result = queries[query]
            check(f"{mode}:{query}", values(result) == sorted(expected, key=repr)
                  and result["openedExpensive"] == 0)

        check(f"{mode}:dynamic dispatch stays partial",
              "UNRESOLVED_DISPATCH" in reasons(queries["dynamic -> ttl#return"]))
        check(f"{mode}:repeated query stable",
              report["paths"][mode][1]["reference"]
              == report["paths"][mode][-1]["reference"])
        check(f"{mode}:post-filter does not prove invocation",
              values(report["postFilter"][mode]) == [7])

    literal_false = report["cases"]["literal-false-branch"]
    check("literal false is structural by default",
          values(literal_false["structural"]) == [30, 60])
    check("truth evaluator prunes literal false",
          values(literal_false["pruned"]) == [60])
    for name, expected in [("zero-loop", [0]), ("one-loop", [11]),
                           ("two-loop", [11, 27]), ("many-loop", [11, 27])]:
        check(f"{name} pruning", values(report["cases"][name]["pruned"]) == expected)
    check("loop cost does not follow iteration count",
          report["cases"]["two-loop"]["pruned"]["statistics"]["constructedNodes"]
          == report["cases"]["many-loop"]["pruned"]["statistics"]["constructedNodes"])
    return checks


if __name__ == "__main__":
    with open(sys.argv[1]) as source:
        checks = verify(json.load(source))
    print(json.dumps(checks, indent=2))
    sys.exit(0 if all(check["passed"] for check in checks) else 1)
