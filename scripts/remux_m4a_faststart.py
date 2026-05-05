#!/usr/bin/env python3
from __future__ import annotations

import argparse
import subprocess
import sys
from pathlib import Path


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Remux .m4a files with -movflags +faststart in-place (no re-encode)."
    )
    p.add_argument("--audio-root", required=True, help="Root dir to process")
    p.add_argument("--dry-run", action="store_true")
    p.add_argument("--limit", type=int, default=0, help="Process at most N files (0 = all)")
    return p.parse_args()


def run(cmd: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, capture_output=True, text=True, encoding="utf-8", errors="replace")


def remux_faststart(src: Path) -> tuple[bool, str]:
    tmp = src.with_name(src.stem + ".__faststart_tmp__.m4a")
    if tmp.exists():
        tmp.unlink()

    cmd = [
        "ffmpeg",
        "-y",
        "-hide_banner",
        "-loglevel",
        "error",
        "-i",
        str(src),
        "-map",
        "0:a",
        "-dn",
        "-sn",
        "-c",
        "copy",
        "-movflags",
        "+faststart",
        str(tmp),
    ]
    proc = run(cmd)
    if proc.returncode != 0 or not tmp.exists() or tmp.stat().st_size <= 0:
        if tmp.exists():
            tmp.unlink()
        return False, proc.stderr.strip() or proc.stdout.strip() or "ffmpeg failed"

    old_size = src.stat().st_size
    new_size = tmp.stat().st_size
    tmp.replace(src)
    return True, f"{old_size}->{new_size}"


def iter_m4a(root: Path) -> list[Path]:
    return sorted([p for p in root.rglob("*.m4a") if p.is_file() and not p.name.startswith(".")])


def main() -> int:
    args = parse_args()
    root = Path(args.audio_root).resolve()
    if not root.is_dir():
        print(f"audio root not found: {root}", file=sys.stderr)
        return 1

    files = iter_m4a(root)
    if args.limit > 0:
        files = files[:args.limit]
    total = len(files)

    print(f"root={root}")
    print(f"m4a files={total}")
    print(f"dry_run={args.dry_run}")

    changed = 0
    failed = 0

    for i, src in enumerate(files, start=1):
        if args.dry_run:
            changed += 1
            print(f"[{i}/{total}] plan {src.name}")
            continue

        ok, msg = remux_faststart(src)
        if ok:
            changed += 1
            print(f"[{i}/{total}] remux {src.name} size={msg}")
        else:
            failed += 1
            print(f"[{i}/{total}] fail {src.name} err={msg}")

    print("----- SUMMARY -----")
    print(f"changed={changed}")
    print(f"failed={failed}")
    return 0 if failed == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
