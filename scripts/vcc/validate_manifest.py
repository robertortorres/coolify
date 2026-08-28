#!/usr/bin/env python3

import json
import re
from pathlib import Path

manifest_path = Path("vcc/manifest.json")
manifest = json.loads(manifest_path.read_text())

required = {
    "distribution",
    "upstream",
    "vcc",
    "integrations",
}

missing = required.difference(manifest)

if missing:
    raise SystemExit(
        f"Missing manifest sections: {sorted(missing)}"
    )

base_commit = manifest["upstream"].get("base_commit", "")

if not re.fullmatch(r"[0-9a-f]{40}", base_commit):
    raise SystemExit(
        "upstream.base_commit must be a full commit SHA"
    )

names = [
    integration.get("name")
    for integration in manifest["integrations"]
]

if any(not name for name in names):
    raise SystemExit("Every integration must have a name")

if len(names) != len(set(names)):
    raise SystemExit("Integration names must be unique")

print(
    "VCC manifest valid:",
    manifest["distribution"]["short_name"],
    base_commit[:9],
)
