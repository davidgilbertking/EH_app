#!/usr/bin/env python3
from __future__ import annotations

import argparse
import math
import subprocess
import sys
from pathlib import Path


AUDIO_EXTS = {".mp3", ".m4a", ".aac", ".ogg", ".oga", ".wav", ".flac"}


def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Split files longer than max seconds into N equal parts (copy codec, no conversion)."
    )
    p.add_argument("--audio-root", required=True, help="Root dir to process in-place")
    p.add_argument("--max-seconds", type=float, default=900.0, help="Max segment duration")
    p.add_argument("--dry-run", action="store_true")
    p.add_argument("--suffix", default="__eq15p", help="Part suffix before index")
    return p.parse_args()


def run(cmd: list[str]) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, capture_output=True, text=True)


def ffprobe_duration(path: Path) -> float | None:
    proc = run(
        [
            "ffprobe",
            "-v",
            "error",
            "-show_entries",
            "format=duration",
            "-of",
            "default=noprint_wrappers=1:nokey=1",
            str(path),
        ]
    )
    if proc.returncode != 0:
        return None
    raw = proc.stdout.strip()
    if not raw:
        return None
    try:
        return float(raw)
    except ValueError:
        return None


def iter_audio(root: Path) -> list[Path]:
    out: list[Path] = []
    for p in root.rglob("*"):
        if p.is_file() and p.suffix.lower() in AUDIO_EXTS and not p.name.startswith("."):
            out.append(p)
    return sorted(out)


def split_copy(src: Path, out: Path, start_sec: float, seg_sec: float) -> bool:
    # Fast split (stream copy). Start not sample-perfect, but very fast and no re-encode.
    cmd = [
        "ffmpeg",
        "-y",
        "-hide_banner",
        "-loglevel",
        "error",
        "-ss",
        f"{start_sec:.3f}",
        "-i",
        str(src),
        "-t",
        f"{seg_sec:.3f}",
        "-vn",
        "-c",
        "copy",
        str(out),
    ]
    proc = run(cmd)
    return proc.returncode == 0 and out.exists() and out.stat().st_size > 0


def main() -> int:
    args = parse_args()
    root = Path(args.audio_root).resolve()
    if not root.is_dir():
        print(f"audio root not found: {root}", file=sys.stderr)
        return 1
    if args.max_seconds <= 0:
        print("max-seconds must be > 0", file=sys.stderr)
        return 1

    files = iter_audio(root)
    print(f"root={root}")
    print(f"audio files={len(files)}")
    print(f"max-seconds={args.max_seconds}")

    candidates: list[tuple[Path, float, int, float]] = []
    for p in files:
        d = ffprobe_duration(p)
        if d is None:
            continue
        if d > args.max_seconds:
            n = int(math.ceil(d / args.max_seconds))
            seg = d / n
            candidates.append((p, d, n, seg))

    print(f"to-split={len(candidates)}")
    print(f"segments-after={sum(n for _, _, n, _ in candidates) + (len(files) - len(candidates))}")

    changed = 0
    failed = 0

    for idx, (src, dur, n, seg) in enumerate(candidates, start=1):
        parent = src.parent
        stem = src.stem
        ext = src.suffix
        outs: list[Path] = []
        tmp_outs: list[Path] = []
        had_error = False

        for i in range(n):
            start = i * seg
            part_dur = seg if i < n - 1 else max(0.05, dur - start)
            out = parent / f"{stem}{args.suffix}{i+1:02d}{ext}"
            tmp = parent / f"{out.stem}.__tmp__{ext}"
            if tmp.exists():
                tmp.unlink()
            if out.exists():
                out.unlink()

            if args.dry_run:
                outs.append(out)
                continue

            ok = split_copy(src, tmp, start, part_dur)
            if not ok:
                had_error = True
                if tmp.exists():
                    tmp.unlink()
                break
            tmp.replace(out)
            outs.append(out)
            tmp_outs.append(tmp)

        if had_error:
            failed += 1
            for p in outs:
                if p.exists():
                    p.unlink()
            print(f"[{idx}/{len(candidates)}] fail: {src.name}")
            continue

        if not args.dry_run:
            old_size = src.stat().st_size
            new_size = sum(p.stat().st_size for p in outs if p.exists())
            src.unlink()
            changed += 1
            print(
                f"[{idx}/{len(candidates)}] split: {src.name} "
                f"dur={dur:.1f}s parts={n} seg={seg:.1f}s size={old_size}->{new_size}"
            )
        else:
            print(f"[{idx}/{len(candidates)}] plan: {src.name} dur={dur:.1f}s parts={n} seg={seg:.1f}s")

    print("----- SUMMARY -----")
    print(f"changed={changed}")
    print(f"failed={failed}")
    return 0 if failed == 0 else 2


if __name__ == "__main__":
    raise SystemExit(main())
