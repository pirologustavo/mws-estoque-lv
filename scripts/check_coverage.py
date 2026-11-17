#!/usr/bin/env python3
import argparse
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument("report", type=Path)
parser.add_argument("--minimum", type=float, default=80.0)
args = parser.parse_args()
if not args.report.is_file():
    print(f"Coverage report not found: {args.report}", file=sys.stderr)
    raise SystemExit(2)
try:
    root = ET.parse(args.report).getroot()
except ET.ParseError as error:
    print(f"Invalid Clover report: {error}", file=sys.stderr)
    raise SystemExit(2)
metrics = root.find("./project/metrics")
if metrics is None:
    print("Clover report has no project metrics", file=sys.stderr)
    raise SystemExit(2)
statements = int(metrics.attrib.get("statements", "0"))
covered = int(metrics.attrib.get("coveredstatements", "0"))
if statements == 0:
    print("Clover report contains no executable statements", file=sys.stderr)
    raise SystemExit(2)
percentage = covered * 100 / statements
print(f"Statement coverage: {percentage:.2f}% (minimum {args.minimum:.2f}%)")
raise SystemExit(0 if percentage >= args.minimum else 1)
