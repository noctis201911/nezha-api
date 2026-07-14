#!/usr/bin/env python3
"""Runtime-topology facts shared by nzdaily and nzwatch."""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from pathlib import Path


DEFAULT_EXPECTED = ("nezha-web", "nezha-queue", "nezha-queue-2")


def load_pm2(stream) -> list[dict]:
    data = json.load(stream)
    if not isinstance(data, list):
        raise ValueError("PM2 jlist must be a JSON array")
    return data


def expected_processes(processes: list[dict], expected: tuple[str, ...]) -> dict[str, dict]:
    wanted = set(expected)
    return {item.get("name"): item for item in processes if item.get("name") in wanted}


def process_snapshot(item: dict) -> dict[str, int | str]:
    env = item.get("pm2_env") or {}
    return {
        "status": str(env.get("status") or "unknown"),
        "restarts": int(env.get("restart_time") or 0),
        "unstable": int(env.get("unstable_restarts") or 0),
    }


def health_report(processes: list[dict], expected: tuple[str, ...] = DEFAULT_EXPECTED) -> tuple[int, list[str]]:
    selected = expected_processes(processes, expected)
    lines: list[str] = []
    critical = False

    for name in expected:
        if name not in selected:
            critical = True
            lines.append(f"{name}: missing from www PM2")
            continue
        snapshot = process_snapshot(selected[name])
        lines.append(
            f"{name}: status={snapshot['status']} restarts={snapshot['restarts']} "
            f"unstable={snapshot['unstable']}"
        )
        if snapshot["status"] != "online":
            critical = True

    return (2 if critical else 0), lines


def read_state(path: Path) -> dict:
    try:
        state = json.loads(path.read_text(encoding="utf-8"))
        return state if isinstance(state, dict) else {}
    except (FileNotFoundError, json.JSONDecodeError, OSError):
        return {}


def write_state(path: Path, snapshots: dict[str, dict[str, int | str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps({"processes": snapshots}, ensure_ascii=False, sort_keys=True)
    descriptor, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            handle.write(payload)
            handle.write("\n")
        os.replace(temporary, path)
    finally:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass


def daily_report(
    processes: list[dict],
    previous: dict,
    expected: tuple[str, ...] = DEFAULT_EXPECTED,
) -> tuple[int, list[str], dict[str, dict[str, int | str]]]:
    selected = expected_processes(processes, expected)
    old = previous.get("processes") if isinstance(previous.get("processes"), dict) else {}
    snapshots: dict[str, dict[str, int | str]] = {}
    lines: list[str] = []
    critical = False
    warning = False

    for name in expected:
        item = selected.get(name)
        if item is None:
            critical = True
            lines.append(f"{name}: MISSING from www PM2")
            continue

        current = process_snapshot(item)
        snapshots[name] = current
        prior = old.get(name) if isinstance(old.get(name), dict) else None
        if current["status"] != "online":
            critical = True

        if prior is None:
            lines.append(
                f"{name}: status={current['status']} restarts={current['restarts']} "
                f"unstable={current['unstable']} (baseline)"
            )
            continue

        restart_delta = int(current["restarts"]) - int(prior.get("restarts", 0))
        unstable_delta = int(current["unstable"]) - int(prior.get("unstable", 0))
        lines.append(
            f"{name}: status={current['status']} restarts={current['restarts']} "
            f"(delta={restart_delta:+d}) unstable={current['unstable']} "
            f"(delta={unstable_delta:+d})"
        )

        if restart_delta < 0 or unstable_delta < 0:
            warning = True
            lines.append(f"  {name}: counters reset; baseline refreshed")
        elif unstable_delta > 0:
            warning = True
            lines.append(f"  {name}: unstable restart increased")
        elif name == "nezha-web" and restart_delta > 0:
            warning = True
            lines.append("  nezha-web: restart increased; correlate with deploy/runtime logs")
        elif name.startswith("nezha-queue") and restart_delta > 0:
            lines.append("  planned-capable queue recycle observed (--max-time=3600); unstable stayed flat")

    return (2 if critical else 1 if warning else 0), lines, snapshots


def fpm_max_children(config: Path, pool: str) -> int:
    section = ""
    for raw in config.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith((";", "#")):
            continue
        if line.startswith("[") and line.endswith("]"):
            section = line[1:-1].strip()
            continue
        if section == pool and line.startswith("pm.max_children") and "=" in line:
            return int(line.split("=", 1)[1].strip())
    raise ValueError(f"pm.max_children not found for pool [{pool}]")


def parse_expected(value: str) -> tuple[str, ...]:
    return tuple(item.strip() for item in value.split(",") if item.strip())


def main() -> int:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    health = subparsers.add_parser("pm2-health")
    health.add_argument("--expected", default=",".join(DEFAULT_EXPECTED))

    daily = subparsers.add_parser("pm2-daily")
    daily.add_argument("--state", required=True, type=Path)
    daily.add_argument("--expected", default=",".join(DEFAULT_EXPECTED))

    fpm = subparsers.add_parser("fpm-max")
    fpm.add_argument("--config", required=True, type=Path)
    fpm.add_argument("--pool", default="www")

    args = parser.parse_args()
    try:
        if args.command == "fpm-max":
            print(fpm_max_children(args.config, args.pool))
            return 0

        processes = load_pm2(sys.stdin)
        expected = parse_expected(args.expected)
        if args.command == "pm2-health":
            code, lines = health_report(processes, expected)
        else:
            previous = read_state(args.state)
            code, lines, snapshots = daily_report(processes, previous, expected)
            write_state(args.state, snapshots)
        print("\n".join(lines))
        return code
    except (OSError, ValueError, json.JSONDecodeError) as error:
        print(f"runtime topology unreadable: {error}")
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
