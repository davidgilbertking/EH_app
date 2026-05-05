#!/usr/bin/env python3
from __future__ import annotations

import argparse
import hashlib
import math
import subprocess
import sys
from pathlib import Path


AUDIO_EXTS = {".mp3", ".m4a", ".aac", ".ogg", ".oga", ".wav", ".flac"}

# User-requested explicit trims to 15-20 min.
CUT_TARGET_PATH_FRAGMENTS = [
    "contacts/antarctica/lake-camp/2 lake camp",
    "ancient/ithaqua/blizzard storm sounds",
    "contacts/other-world/underworld/d&d ambience -  dark, dank cave",
    "contacts/dreamlands/underworld/3 underworld --- d&d ambience -  dark, dank cave",
    "contacts/general/sea/space-2/d&d ambience -  calm sea sailing",
    "contacts/general/sea/space-12/d&d ambience - ship cabin",
    "contacts/general/sea/space-13/d&d ambience - ship in storm",
    "contacts/egypt/tel-el-amarna/amarna -- mysterious ancient egyptian tomb ambience",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Cut specific tracks, then split+convert remaining tracks."
    )
    parser.add_argument(
        "--audio-root",
        default="storage/app/private/audio",
        help="Audio root directory",
    )
    parser.add_argument(
        "--cut-seconds",
        type=int,
        default=20 * 60,
        help="Trim duration for explicit cut targets",
    )
    parser.add_argument(
        "--split-seconds",
        type=int,
        default=30 * 60,
        help="Split threshold/segment size for remaining tracks",
    )
    parser.add_argument(
        "--bitrate",
        default="64k",
        help="AAC bitrate for conversion, e.g. 64k/80k/96k",
    )
    parser.add_argument(
        "--skip-cut",
        action="store_true",
        help="Skip explicit cut stage",
    )
    parser.add_argument(
        "--skip-existing-m4a",
        action="store_true",
        default=True,
        help="Skip files already in .m4a format (resume speed mode)",
    )
    parser.add_argument(
        "--shard-count",
        type=int,
        default=1,
        help="Total shard workers. 1 = no sharding",
    )
    parser.add_argument(
        "--shard-index",
        type=int,
        default=0,
        help="0-based shard index",
    )
    return parser.parse_args()


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


def iter_audio_files(root: Path) -> list[Path]:
    out: list[Path] = []
    for p in root.rglob("*"):
        if not p.is_file():
            continue
        if p.name.startswith("."):
            continue
        if p.suffix.lower() in AUDIO_EXTS:
            out.append(p)
    return sorted(out)


def path_key(root: Path, p: Path) -> str:
    rel = p.relative_to(root).as_posix().lower()
    return rel


def matches_cut_target(root: Path, p: Path) -> bool:
    key = path_key(root, p)
    return any(fragment in key for fragment in CUT_TARGET_PATH_FRAGMENTS)


def in_shard(root: Path, p: Path, shard_count: int, shard_index: int) -> bool:
    if shard_count <= 1:
        return True
    key = path_key(root, p).encode("utf-8", errors="ignore")
    h = hashlib.md5(key).hexdigest()
    bucket = int(h[:8], 16) % shard_count
    return bucket == shard_index


def ffmpeg_copy_trim(src: Path, dst: Path, seconds: int) -> bool:
    cmd = [
        "ffmpeg",
        "-y",
        "-hide_banner",
        "-loglevel",
        "error",
        "-i",
        str(src),
        "-t",
        str(seconds),
        "-c",
        "copy",
        str(dst),
    ]
    proc = run(cmd)
    return proc.returncode == 0 and dst.exists() and dst.stat().st_size > 0


def ffmpeg_convert_segment(
    src: Path,
    dst: Path,
    *,
    bitrate: str,
    start: float | None = None,
    duration: float | None = None,
) -> bool:
    cmd = [
        "ffmpeg",
        "-y",
        "-hide_banner",
        "-loglevel",
        "error",
    ]
    if start is not None:
        cmd += ["-ss", f"{start:.3f}"]
    cmd += ["-i", str(src)]
    if duration is not None:
        cmd += ["-t", f"{duration:.3f}"]
    cmd += [
        "-vn",
        "-c:a",
        "aac",
        "-b:a",
        bitrate,
        "-movflags",
        "+faststart",
        str(dst),
    ]
    proc = run(cmd)
    return proc.returncode == 0 and dst.exists() and dst.stat().st_size > 0


def total_size(paths: list[Path]) -> int:
    return sum(p.stat().st_size for p in paths if p.exists())


def main() -> int:
    args = parse_args()
    if args.shard_count < 1:
        print("shard-count must be >= 1", file=sys.stderr)
        return 1
    if args.shard_index < 0 or args.shard_index >= args.shard_count:
        print("shard-index must be in [0, shard-count)", file=sys.stderr)
        return 1

    root = Path(args.audio_root).resolve()
    if not root.is_dir():
        print(f"audio root not found: {root}", file=sys.stderr)
        return 1

    all_files_before = iter_audio_files(root)
    size_before = total_size(all_files_before)
    print(f"audio files before: {len(all_files_before)}")
    print(f"total size before: {size_before} bytes")
    print(f"shard: {args.shard_index}/{args.shard_count}")

    # 1) Explicit cuts first.
    cut_changed = 0
    cut_candidates = [] if args.skip_cut else [
        p for p in all_files_before
        if matches_cut_target(root, p) and in_shard(root, p, args.shard_count, args.shard_index)
    ]
    print(f"cut candidates: {len(cut_candidates)}")

    cut_set: set[Path] = set()
    for idx, src in enumerate(cut_candidates, start=1):
        dur = ffprobe_duration(src)
        if dur is None:
            print(f"[cut {idx}/{len(cut_candidates)}] skip(no duration): {src}")
            continue
        cut_set.add(src)
        if dur <= args.cut_seconds + 1:
            print(f"[cut {idx}/{len(cut_candidates)}] keep(short): {src.name} ({dur:.1f}s)")
            continue

        tmp = src.with_name(f"{src.stem}.__tmpcut__{src.suffix}")
        if tmp.exists():
            tmp.unlink()

        ok = ffmpeg_copy_trim(src, tmp, args.cut_seconds)
        if not ok:
            print(f"[cut {idx}/{len(cut_candidates)}] fail: {src}")
            if tmp.exists():
                tmp.unlink()
            continue

        old_size = src.stat().st_size
        new_size = tmp.stat().st_size
        tmp.replace(src)
        cut_changed += 1
        print(
            f"[cut {idx}/{len(cut_candidates)}] done: {src.name} "
            f"{old_size}->{new_size} bytes"
        )

    # 2) Split+convert all remaining tracks (excluding explicit cut set).
    files_now = iter_audio_files(root)
    remaining = [p for p in files_now if p not in cut_set]
    if args.skip_existing_m4a:
        remaining = [p for p in remaining if p.suffix.lower() != ".m4a" and "__part" not in p.stem]
    remaining = [p for p in remaining if in_shard(root, p, args.shard_count, args.shard_index)]
    print(f"remaining for split/convert: {len(remaining)}")

    conv_changed = 0
    conv_skipped_not_smaller = 0
    conv_failed = 0

    for idx, src in enumerate(remaining, start=1):
        dur = ffprobe_duration(src)
        if dur is None:
            print(f"[conv {idx}/{len(remaining)}] skip(no duration): {src}")
            continue

        old_size = src.stat().st_size
        stem = src.stem
        parent = src.parent

        # Long file => split to parts then convert each part.
        if dur > args.split_seconds + 1:
            part_count = int(math.ceil(dur / args.split_seconds))
            created: list[Path] = []
            failed = False
            for part_idx in range(part_count):
                start = part_idx * args.split_seconds
                seg_dur = min(args.split_seconds, dur - start)
                out = parent / f"{stem}__part{part_idx + 1:02d}.m4a"
                tmp = parent / f"{out.stem}.__tmp__.m4a"
                if tmp.exists():
                    tmp.unlink()
                if out.exists():
                    out.unlink()

                ok = ffmpeg_convert_segment(
                    src,
                    tmp,
                    bitrate=args.bitrate,
                    start=float(start),
                    duration=float(seg_dur),
                )
                if not ok:
                    failed = True
                    if tmp.exists():
                        tmp.unlink()
                    break
                tmp.replace(out)
                created.append(out)

            if failed:
                for p in created:
                    if p.exists():
                        p.unlink()
                conv_failed += 1
                print(f"[conv {idx}/{len(remaining)}] fail(split): {src.name}")
                continue

            new_size = total_size(created)
            if new_size >= old_size:
                for p in created:
                    if p.exists():
                        p.unlink()
                conv_skipped_not_smaller += 1
                print(
                    f"[conv {idx}/{len(remaining)}] keep(orig, not smaller): "
                    f"{src.name} {old_size}->{new_size}"
                )
                continue

            src.unlink()
            conv_changed += 1
            print(
                f"[conv {idx}/{len(remaining)}] split+conv: {src.name} "
                f"{old_size}->{new_size} bytes parts={len(created)}"
            )
            continue

        # Shorter file => single convert.
        out_final = parent / f"{stem}.m4a"
        tmp = parent / f"{out_final.stem}.__tmp__.m4a"
        if tmp.exists():
            tmp.unlink()

        # If source already m4a and same final name, remove temp/final conflict.
        if src.suffix.lower() == ".m4a" and out_final == src:
            out_final = parent / f"{stem}.__recode__.m4a"
            tmp = parent / f"{out_final.stem}.__tmp__.m4a"
            if tmp.exists():
                tmp.unlink()
            if out_final.exists():
                out_final.unlink()
        elif out_final.exists():
            out_final.unlink()

        ok = ffmpeg_convert_segment(src, tmp, bitrate=args.bitrate)
        if not ok:
            if tmp.exists():
                tmp.unlink()
            conv_failed += 1
            print(f"[conv {idx}/{len(remaining)}] fail(single): {src.name}")
            continue

        tmp.replace(out_final)
        new_size = out_final.stat().st_size
        if new_size >= old_size:
            out_final.unlink()
            conv_skipped_not_smaller += 1
            print(
                f"[conv {idx}/{len(remaining)}] keep(orig, not smaller): "
                f"{src.name} {old_size}->{new_size}"
            )
            continue

        if src.exists():
            src.unlink()

        # For recode temp name, restore original filename.
        if out_final.name.endswith(".__recode__.m4a"):
            restore = parent / f"{stem}.m4a"
            if restore.exists():
                restore.unlink()
            out_final.replace(restore)
            new_size = restore.stat().st_size

        conv_changed += 1
        print(
            f"[conv {idx}/{len(remaining)}] conv: {src.name} "
            f"{old_size}->{new_size} bytes"
        )

    files_after = iter_audio_files(root)
    size_after = total_size(files_after)
    delta = size_after - size_before
    pct = ((size_after / size_before) * 100.0) if size_before else 0.0

    print("----- SUMMARY -----")
    print(f"cut changed: {cut_changed}")
    print(f"converted changed: {conv_changed}")
    print(f"skipped(not smaller): {conv_skipped_not_smaller}")
    print(f"failed: {conv_failed}")
    print(f"files before: {len(all_files_before)}")
    print(f"files after: {len(files_after)}")
    print(f"size before: {size_before} bytes")
    print(f"size after: {size_after} bytes")
    print(f"delta: {delta} bytes")
    print(f"after/before: {pct:.2f}%")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
