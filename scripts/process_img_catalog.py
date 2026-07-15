#!/usr/bin/env python3
"""Convert img/*.png catalog shots to assets/products/*.jpg and remove source files."""
from __future__ import annotations

from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
IMG = ROOT / "img"
OUT = ROOT / "assets" / "products"

# number-only PNG -> destination JPG (product photo)
PRODUCTS = {
    1: "doganlar-pin-cutting-chisel.jpg",
    2: "doganlar-spring-cultivator.jpg",
    3: "doganlar-tiller.jpg",
    4: "doganlar-rotovator.jpg",
    5: "romsan-r100tahkp4-tipper.jpg",
    6: "romsan-r100tkg-manure-spreader.jpg",
    7: "romsan-r220tahk-robust-tipper.jpg",
    8: "romsan-r180usga-tipper.jpg",
    9: "romsan-r140csga4p-l-tipper.jpg",
    10: "romsan-r16tasgap4-tipper.jpg",
}


def png_to_jpg(src: Path, dest: Path) -> None:
    with Image.open(src) as im:
        rgb = im.convert("RGB")
        dest.parent.mkdir(parents=True, exist_ok=True)
        rgb.save(dest, "JPEG", quality=90, optimize=True)
    print(f"saved {dest.relative_to(ROOT)}")


def main() -> None:
    if not IMG.is_dir():
        raise SystemExit(f"Missing folder: {IMG}")

    for num, filename in PRODUCTS.items():
        src = IMG / f"{num}.png"
        if not src.is_file():
            raise SystemExit(f"Missing source image: {src}")
        png_to_jpg(src, OUT / filename)

    removed = 0
    for path in sorted(IMG.iterdir()):
        if path.is_file():
            path.unlink()
            removed += 1
            print(f"deleted {path.relative_to(ROOT)}")

    if not any(IMG.iterdir()):
        print(f"img/ is empty ({removed} files removed)")


if __name__ == "__main__":
    main()
