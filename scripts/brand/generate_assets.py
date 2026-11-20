#!/usr/bin/env python3
"""Generate all Veltiq raster assets (web, backend, Android, Windows) with Pillow.

Replicates the source badge from `Veltiq logo/12-circle-badge.svg`:
dark circular background, two gradient rings and a gradient "V".

    python scripts/brand/generate_assets.py
"""

from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parents[2]

DARK = (0x0F, 0x17, 0x2A, 255)
SKY = (0x0E, 0xA5, 0xE9, 255)
INDIGO = (0x63, 0x66, 0xF1, 255)
VIOLET = (0x8B, 0x5C, 0xF6, 255)
WHITE = (0xFF, 0xFF, 0xFF, 255)
TRANSPARENT = (0, 0, 0, 0)

BASE = 1024
SOURCE = 512

_GRAD_CACHE: dict[int, Image.Image] = {}


def _lerp(a: tuple, b: tuple, t: float) -> tuple:
    return tuple(int(a[i] + (b[i] - a[i]) * t) for i in range(4))


def _gradient_color(t: float) -> tuple:
    if t <= 0.5:
        return _lerp(SKY, INDIGO, t / 0.5)
    return _lerp(INDIGO, VIOLET, (t - 0.5) / 0.5)


def diagonal_gradient(size: int) -> Image.Image:
    if size in _GRAD_CACHE:
        return _GRAD_CACHE[size]

    img = Image.new("RGBA", (size, size))
    denom = 2 * (size - 1) if size > 1 else 1
    data = []
    for y in range(size):
        for x in range(size):
            data.append(_gradient_color((x + y) / denom))
    img.putdata(data)
    _GRAD_CACHE[size] = img
    return img


def full_circle_mask(size: int) -> Image.Image:
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).ellipse([0, 0, size - 1, size - 1], fill=255)
    return mask


def ring_mask(size: int, radius: float, width: float) -> Image.Image:
    mask = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(mask)
    center = (size - 1) / 2
    r = max(radius, 1.0)
    w = max(width, 1.0)
    draw.ellipse([center - r, center - r, center + r, center + r], fill=255)
    inner = r - w
    if inner > 0:
        draw.ellipse([center - inner, center - inner, center + inner, center + inner], fill=0)
    return mask


def v_points(size: int) -> list[tuple[float, float]]:
    scale = size / SOURCE
    return [(176 * scale, 160 * scale), (256 * scale, 360 * scale), (336 * scale, 160 * scale)]


def v_mask(size: int, stroke: float) -> Image.Image:
    mask = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(mask)
    points = v_points(size)
    width = max(1, int(round(stroke)))
    draw.line(points, fill=255, width=width, joint="curve")
    radius = max(1, width // 2)
    for px, py in (points[0], points[-1]):
        draw.ellipse([px - radius, py - radius, px + radius, py + radius], fill=255)
    return mask


def compose_badge(size: int, square: bool = False) -> Image.Image:
    icon = Image.new("RGBA", (size, size), TRANSPARENT)
    background = Image.new("RGBA", (size, size), DARK)

    if square:
        icon.alpha_composite(background)
    else:
        icon.paste(background, (0, 0), full_circle_mask(size))

    gradient = diagonal_gradient(size)
    empty = Image.new("RGBA", (size, size), TRANSPARENT)

    outer = ring_mask(size, size * 180 / SOURCE, size * 12 / SOURCE)
    icon.alpha_composite(Image.composite(gradient, empty, outer))

    inner = ring_mask(size, size * 160 / SOURCE, size * 4 / SOURCE).point(lambda v: int(v * 0.3))
    icon.alpha_composite(Image.composite(gradient, empty, inner))

    glyph = v_mask(size, size * 24 / SOURCE)
    icon.alpha_composite(Image.composite(gradient, empty, glyph))

    return icon


def build_small(size: int) -> Image.Image:
    """Crisp circular favicon rendered directly at the target size."""
    icon = Image.new("RGBA", (size, size), TRANSPARENT)
    icon.paste(Image.new("RGBA", (size, size), DARK), (0, 0), full_circle_mask(size))

    gradient = diagonal_gradient(size)
    empty = Image.new("RGBA", (size, size), TRANSPARENT)

    outer = ring_mask(size, size * 180 / SOURCE, max(1, round(size * 12 / SOURCE)))
    icon.alpha_composite(Image.composite(gradient, empty, outer))

    glyph = v_mask(size, max(2, round(size * 0.11)))
    icon.alpha_composite(Image.composite(gradient, empty, glyph))

    return icon


def build_notification(size: int) -> Image.Image:
    """White V silhouette for Android status-bar notifications."""
    icon = Image.new("RGBA", (size, size), TRANSPARENT)
    mask = v_mask(size, max(1, round(size * 0.11)))
    icon.paste(Image.new("RGBA", (size, size), WHITE), (0, 0), mask)
    return icon


def scaled(image: Image.Image, size: int) -> Image.Image:
    return image.resize((size, size), Image.LANCZOS)


def rounded_circle(image: Image.Image) -> Image.Image:
    size = image.size[0]
    out = Image.new("RGBA", (size, size), TRANSPARENT)
    out.paste(image, (0, 0), full_circle_mask(size))
    return out


def write(image: Image.Image, path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    image.save(path)
    print(f"  {path.relative_to(ROOT)}  ({image.size[0]}x{image.size[1]})")


def main() -> None:
    icon = compose_badge(BASE, square=False)
    apple = compose_badge(BASE, square=True)

    print("Web (frontend/public):")
    icon_svg = (ROOT / "scripts/brand/veltiq-icon.svg").read_text(encoding="utf-8")
    (ROOT / "frontend/public/favicon.svg").write_text(icon_svg, encoding="utf-8")
    (ROOT / "frontend/public/veltiq-icon.svg").write_text(icon_svg, encoding="utf-8")
    write(build_small(16), ROOT / "frontend/public/favicon-16.png")
    write(build_small(32), ROOT / "frontend/public/favicon-32.png")
    write(scaled(apple, 180), ROOT / "frontend/public/apple-touch-icon.png")
    write(scaled(icon, 192), ROOT / "frontend/public/veltiq-icon-192.png")
    write(scaled(icon, 512), ROOT / "frontend/public/veltiq-icon-512.png")
    scaled(icon, 256).save(
        ROOT / "frontend/public/favicon.ico",
        format="ICO",
        sizes=[(16, 16), (32, 32), (48, 48)],
    )
    print("  frontend/public/favicon.ico  (16,32,48)")

    print("Backend (backend/public):")
    (ROOT / "backend/public/favicon.ico").write_bytes((ROOT / "frontend/public/favicon.ico").read_bytes())
    (ROOT / "backend/public/veltiq-icon.svg").write_text(icon_svg, encoding="utf-8")
    print("  backend/public/favicon.ico")
    print("  backend/public/veltiq-icon.svg")

    print("Android launcher icons:")
    densities = {"mdpi": 48, "hdpi": 72, "xhdpi": 96, "xxhdpi": 144, "xxxhdpi": 192}
    for name, size in densities.items():
        write(scaled(icon, size), ROOT / f"android/app/src/main/res/mipmap-{name}/ic_launcher.png")
        write(rounded_circle(scaled(icon, size)), ROOT / f"android/app/src/main/res/mipmap-{name}/ic_launcher_round.png")

    print("Android notification icon:")
    notif = {"mdpi": 24, "hdpi": 36, "xhdpi": 48, "xxhdpi": 72, "xxxhdpi": 96}
    for name, size in notif.items():
        write(build_notification(size), ROOT / f"android/app/src/main/res/drawable-{name}/ic_notification.png")

    print("Windows desktop:")
    write(scaled(icon, 256), ROOT / "android/desktop/src/main/resources/veltiq-icon.png")
    ico = ROOT / "android/desktop/icons/veltiq.ico"
    ico.parent.mkdir(parents=True, exist_ok=True)
    scaled(icon, 256).save(
        ico,
        format="ICO",
        sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)],
    )
    print("  android/desktop/icons/veltiq.ico  (16..256)")

    print("Done.")


if __name__ == "__main__":
    main()
