#!/usr/bin/env python3
"""Generate the Doctor Bike 9:16 animated splash GIFs.

The supplied SVG is the single source of truth for the final artwork.  It
contains an embedded transparent PNG, so this generator extracts those exact
pixels, derives animation masks from them, and never redraws the logo or text.

Requires: Pillow
"""

from __future__ import annotations

import argparse
import base64
import math
import re
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageFilter


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SVG = Path(r"C:\Users\hp\Downloads\doctor_bike_logo_exact.svg")
DEFAULT_REFERENCE_DIR = Path(r"C:\Users\hp\Downloads")
DEFAULT_OUTPUT = PROJECT_ROOT / "assets" / "images" / "doctor_bike_splash.gif"
DEFAULT_PREVIEW = (
    PROJECT_ROOT / "assets" / "images" / "doctor_bike_splash_preview.gif"
)

# Storyboard order is intentionally the reverse of the filename suffix order.
REFERENCE_NAMES = (
    "ChatGPT Image Oct 6, 2026, 08_54_47 PM-1.png",
    "ChatGPT Image Oct 6, 2026, 08_54_48 PM-2.png",
    "ChatGPT Image Oct 6, 2026, 08_54_51 PM-3.png",
    "ChatGPT Image Oct 6, 2026, 08_54_55 PM-4.png",
    "ChatGPT Image Oct 6, 2026, 08_54_57 PM-5.png",
    "ChatGPT Image Oct 6, 2026, 08_55_00 PM-6.png",
)

PURPLE = (108, 85, 220)
LIGHT_PURPLE = (157, 132, 245)
WHITE = (255, 255, 255)
FRAME_INTERVAL_MS = 40
REGULAR_FRAME_COUNT = 63
FINAL_HOLD_MS = 680
TOTAL_DURATION_MS = REGULAR_FRAME_COUNT * FRAME_INTERVAL_MS + FINAL_HOLD_MS


def clamp(value: float, low: float = 0.0, high: float = 1.0) -> float:
    return max(low, min(high, value))


def ease_out_cubic(value: float) -> float:
    value = clamp(value)
    return 1.0 - (1.0 - value) ** 3


def ease_in_out_cubic(value: float) -> float:
    value = clamp(value)
    if value < 0.5:
        return 4.0 * value**3
    return 1.0 - (-2.0 * value + 2.0) ** 3 / 2.0


def phase(time_s: float, start: float, end: float, easing=ease_out_cubic) -> float:
    return easing((time_s - start) / (end - start))


def extract_embedded_png(svg_path: Path) -> Image.Image:
    svg_text = svg_path.read_text(encoding="utf-8")
    match = re.search(
        r"href\s*=\s*['\"]data:image/png;base64,([^'\"]+)['\"]", svg_text
    )
    if not match:
        raise ValueError(f"No embedded PNG data URI found in {svg_path}")
    from io import BytesIO

    return Image.open(BytesIO(base64.b64decode(match.group(1)))).convert("RGBA")


def validate_storyboard(reference_dir: Path) -> None:
    missing = [name for name in REFERENCE_NAMES if not (reference_dir / name).is_file()]
    if missing:
        print("Storyboard references not found (generation can still continue):")
        for name in missing:
            print(f"  - {reference_dir / name}")
        return

    sizes = []
    for name in REFERENCE_NAMES:
        with Image.open(reference_dir / name) as image:
            sizes.append(image.size)
    print(f"Validated 6 storyboard references: {sizes[0][0]}x{sizes[0][1]}")


def scaled_mask(mask: Image.Image, size: tuple[int, int]) -> Image.Image:
    return mask.resize(size, Image.Resampling.LANCZOS)


def multiply_alpha(mask: Image.Image, opacity: float) -> Image.Image:
    opacity = clamp(opacity)
    if opacity >= 0.999:
        return mask
    return mask.point(lambda value: round(value * opacity))


def image_with_mask(source: Image.Image, mask: Image.Image) -> Image.Image:
    layer = source.copy()
    layer.putalpha(mask)
    return layer


def colored_mask(
    mask: Image.Image,
    color: tuple[int, int, int],
    opacity: float,
    blur: float = 0.0,
) -> Image.Image:
    alpha = mask.filter(ImageFilter.GaussianBlur(blur)) if blur else mask
    alpha = multiply_alpha(alpha, opacity)
    layer = Image.new("RGBA", mask.size, (*color, 0))
    layer.putalpha(alpha)
    return layer


@dataclass
class Artwork:
    source: Image.Image
    body: Image.Image
    wheels: Image.Image
    handlebar: Image.Image
    wordmark: Image.Image
    tagline: Image.Image
    icon_and_wordmark: Image.Image
    logo_xy: tuple[int, int]
    logo_size: tuple[int, int]
    scale: float


class SplashRenderer:
    def __init__(self, original: Image.Image, canvas_size: tuple[int, int]):
        self.width, self.height = canvas_size
        logo_width = round(self.width * 0.79)
        logo_height = round(original.height * logo_width / original.width)
        resized = original.resize((logo_width, logo_height), Image.Resampling.LANCZOS)
        logo_x = (self.width - logo_width) // 2
        logo_y = (self.height - logo_height) // 2
        scale = logo_width / original.width

        original_alpha = original.getchannel("A")
        body_core = Image.new("L", original.size, 0)
        body_pixels = body_core.load()
        source_pixels = original.load()
        for y in range(0, min(600, original.height)):
            for x in range(original.width):
                red, green, blue, alpha = source_pixels[x, y]
                if alpha and red > 45 and green > 40 and blue > 95:
                    body_pixels[x, y] = 255
        body_region = body_core.filter(ImageFilter.MaxFilter(7))
        body_mask_original = ImageChops.multiply(original_alpha, body_region)

        wheel_region = Image.new("L", original.size, 0)
        wheel_draw = ImageDraw.Draw(wheel_region)
        for center_x, center_y, radius in ((135, 449, 116), (666, 449, 116)):
            wheel_draw.ellipse(
                (
                    center_x - radius,
                    center_y - radius,
                    center_x + radius,
                    center_y + radius,
                ),
                fill=255,
            )
        dark_region = Image.new("L", original.size, 0)
        dark_pixels = dark_region.load()
        for y in range(0, min(600, original.height)):
            for x in range(original.width):
                red, green, blue, alpha = source_pixels[x, y]
                if alpha and red < 55 and green < 55 and blue < 100:
                    dark_pixels[x, y] = 255
        dark_region = dark_region.filter(ImageFilter.MaxFilter(5))
        wheel_mask_original = ImageChops.multiply(
            original_alpha, ImageChops.multiply(wheel_region, dark_region)
        )

        wordmark_region = Image.new("L", original.size, 0)
        ImageDraw.Draw(wordmark_region).rectangle((0, 590, original.width, 760), fill=255)
        wordmark_original = ImageChops.multiply(original_alpha, wordmark_region)

        tagline_region = Image.new("L", original.size, 0)
        ImageDraw.Draw(tagline_region).rectangle((0, 760, original.width, original.height), fill=255)
        tagline_original = ImageChops.multiply(original_alpha, tagline_region)

        icon_region = Image.new("L", original.size, 0)
        ImageDraw.Draw(icon_region).rectangle((0, 0, original.width, 590), fill=255)
        icon_original = ImageChops.multiply(original_alpha, icon_region)
        assigned_icon = ImageChops.lighter(body_mask_original, wheel_mask_original)
        handlebar_original = ImageChops.subtract(icon_original, assigned_icon)

        logo_size = resized.size
        body = scaled_mask(body_mask_original, logo_size)
        wheels = scaled_mask(wheel_mask_original, logo_size)
        handlebar = scaled_mask(handlebar_original, logo_size)
        wordmark = scaled_mask(wordmark_original, logo_size)
        tagline = scaled_mask(tagline_original, logo_size)
        icon_and_wordmark = ImageChops.subtract(resized.getchannel("A"), tagline)

        self.art = Artwork(
            source=resized,
            body=body,
            wheels=wheels,
            handlebar=handlebar,
            wordmark=wordmark,
            tagline=tagline,
            icon_and_wordmark=icon_and_wordmark,
            logo_xy=(logo_x, logo_y),
            logo_size=logo_size,
            scale=scale,
        )

    def _wheel_reveal(self, progress: float) -> Image.Image:
        reveal = Image.new("L", self.art.logo_size, 0)
        draw = ImageDraw.Draw(reveal)
        for center_x, center_y, radius in ((135, 449, 123), (666, 449, 123)):
            cx = center_x * self.art.scale
            cy = center_y * self.art.scale
            r = radius * self.art.scale
            draw.pieslice(
                (cx - r, cy - r, cx + r, cy + r),
                start=-90,
                end=-90 + 360 * clamp(progress),
                fill=255,
            )
        reveal = reveal.filter(ImageFilter.GaussianBlur(max(0.7, self.art.scale)))
        return ImageChops.multiply(self.art.wheels, reveal)

    def _horizontal_reveal(self, mask: Image.Image, progress: float) -> Image.Image:
        reveal = Image.new("L", self.art.logo_size, 0)
        edge = round(self.art.logo_size[0] * clamp(progress))
        ImageDraw.Draw(reveal).rectangle((0, 0, edge, self.art.logo_size[1]), fill=255)
        reveal = reveal.filter(ImageFilter.GaussianBlur(max(1.0, 4 * self.art.scale)))
        return ImageChops.multiply(mask, reveal)

    def _upward_reveal(self, mask: Image.Image, progress: float) -> Image.Image:
        reveal = Image.new("L", self.art.logo_size, 0)
        start_y = 520 * self.art.scale
        end_y = 5 * self.art.scale
        cutoff = start_y + (end_y - start_y) * clamp(progress)
        ImageDraw.Draw(reveal).rectangle(
            (0, cutoff, self.art.logo_size[0], self.art.logo_size[1]), fill=255
        )
        reveal = reveal.filter(ImageFilter.GaussianBlur(max(1.0, 4 * self.art.scale)))
        return ImageChops.multiply(mask, reveal)

    def _effects_layer(
        self,
        time_s: float,
        wheels_visible: Image.Image,
        body_visible: Image.Image,
        handle_visible: Image.Image,
    ) -> Image.Image:
        layer = Image.new("RGBA", (self.width, self.height), (0, 0, 0, 0))
        logo_x, logo_y = self.art.logo_xy
        spark_x = logo_x + round(410 * self.art.scale)
        spark_y = logo_y + round(420 * self.art.scale)

        # The storyboard's first beat is a clearly readable energy event, not
        # just a small glow.  Give it its own time before the wheels take over.
        spark_in = phase(time_s, 0.04, 0.28)
        orbit_progress = phase(time_s, 0.10, 0.62)
        particle_fade = 1.0 - phase(time_s, 0.82, 1.24, ease_in_out_cubic)
        spark_strength = spark_in * particle_fade
        if spark_strength > 0.001:
            glow_radius = round((48 + 34 * spark_in) * self.art.scale)
            glow_mask = Image.new("L", layer.size, 0)
            glow_draw = ImageDraw.Draw(glow_mask)
            glow_draw.ellipse(
                (
                    spark_x - glow_radius,
                    spark_y - glow_radius,
                    spark_x + glow_radius,
                    spark_y + glow_radius,
                ),
                fill=round(135 * spark_strength),
            )
            glow_mask = glow_mask.filter(
                ImageFilter.GaussianBlur(max(8, round(34 * self.art.scale)))
            )
            layer.alpha_composite(colored_mask(glow_mask, PURPLE, 0.90))

            inner_glow = Image.new("L", layer.size, 0)
            inner_draw = ImageDraw.Draw(inner_glow)
            inner_radius = round((17 + 9 * spark_in) * self.art.scale)
            inner_draw.ellipse(
                (
                    spark_x - inner_radius,
                    spark_y - inner_radius,
                    spark_x + inner_radius,
                    spark_y + inner_radius,
                ),
                fill=round(220 * spark_strength),
            )
            inner_glow = inner_glow.filter(
                ImageFilter.GaussianBlur(max(3, round(9 * self.art.scale)))
            )
            layer.alpha_composite(colored_mask(inner_glow, LIGHT_PURPLE, 0.92))

            # Two clean elliptical energy trails, revealed continuously in
            # opposite directions like the large orbital sweep in frame one.
            orbit_glow = Image.new("RGBA", layer.size, (0, 0, 0, 0))
            orbit_sharp = Image.new("RGBA", layer.size, (0, 0, 0, 0))
            glow_draw = ImageDraw.Draw(orbit_glow)
            sharp_draw = ImageDraw.Draw(orbit_sharp)

            def orbit_points(
                radius_x: float,
                radius_y: float,
                rotation: float,
                start_angle: float,
                direction: float,
            ) -> list[tuple[float, float]]:
                point_count = max(2, round(150 * orbit_progress))
                points = []
                cos_rotation = math.cos(rotation)
                sin_rotation = math.sin(rotation)
                for point_index in range(point_count):
                    fraction = point_index / 149.0
                    angle = start_angle + direction * math.tau * fraction
                    local_x = math.cos(angle) * radius_x
                    local_y = math.sin(angle) * radius_y
                    points.append(
                        (
                            spark_x
                            + local_x * cos_rotation
                            - local_y * sin_rotation,
                            spark_y
                            + local_x * sin_rotation
                            + local_y * cos_rotation,
                        )
                    )
                return points

            orbit_specs = (
                (270, 76, -0.42, 2.72, 1.0),
                (236, 59, -0.52, -0.15, -1.0),
            )
            for index, (radius_x, radius_y, rotation, start, direction) in enumerate(
                orbit_specs
            ):
                points = orbit_points(
                    radius_x * self.art.scale,
                    radius_y * self.art.scale,
                    rotation,
                    start,
                    direction,
                )
                opacity = round((155 - index * 28) * spark_strength)
                glow_draw.line(
                    points,
                    fill=(*PURPLE, opacity),
                    width=max(4, round((7 - index) * self.art.scale)),
                    joint="curve",
                )
                sharp_draw.line(
                    points,
                    fill=(*LIGHT_PURPLE, min(235, opacity + 54)),
                    width=max(1, round((2.5 - index * 0.5) * self.art.scale)),
                    joint="curve",
                )
            orbit_glow = orbit_glow.filter(
                ImageFilter.GaussianBlur(max(2, round(7 * self.art.scale)))
            )
            layer.alpha_composite(orbit_glow)
            layer.alpha_composite(orbit_sharp)

            rays = Image.new("RGBA", layer.size, (0, 0, 0, 0))
            ray_draw = ImageDraw.Draw(rays)
            ray_lengths = (174, 78, 92, 188, 80, 66, 168, 72, 94, 184, 76, 64)
            for index, length in enumerate(ray_lengths):
                angle = index * math.tau / len(ray_lengths) + time_s * 0.18
                length_px = length * self.art.scale * (0.55 + 0.45 * spark_in)
                end = (
                    spark_x + math.cos(angle) * length_px,
                    spark_y + math.sin(angle) * length_px,
                )
                ray_draw.line(
                    ((spark_x, spark_y), end),
                    fill=(*LIGHT_PURPLE, round((150 - index % 3 * 22) * spark_strength)),
                    width=max(1, round((1.5 if index % 2 == 0 else 1.0) * self.art.scale)),
                )
            ray_glow = rays.filter(
                ImageFilter.GaussianBlur(max(2, round(4 * self.art.scale)))
            )
            layer.alpha_composite(ray_glow)
            rays = rays.filter(ImageFilter.GaussianBlur(max(0.35, self.art.scale * 0.35)))
            layer.alpha_composite(rays)

            core = Image.new("RGBA", layer.size, (0, 0, 0, 0))
            core_draw = ImageDraw.Draw(core)
            core_radius = max(3, round(7 * self.art.scale * spark_in))
            ring_radius = max(6, round(14 * self.art.scale * spark_in))
            core_draw.ellipse(
                (
                    spark_x - ring_radius,
                    spark_y - ring_radius,
                    spark_x + ring_radius,
                    spark_y + ring_radius,
                ),
                fill=(*LIGHT_PURPLE, round(180 * spark_strength)),
            )
            core_draw.ellipse(
                (
                    spark_x - core_radius,
                    spark_y - core_radius,
                    spark_x + core_radius,
                    spark_y + core_radius,
                ),
                fill=(255, 255, 255, round(255 * spark_strength)),
            )
            layer.alpha_composite(core)

            particles = Image.new("RGBA", layer.size, (0, 0, 0, 0))
            particle_draw = ImageDraw.Draw(particles)
            for index in range(20):
                angle = index * 2.399963 + 0.35
                spread = (66 + (index % 6) * 28) * self.art.scale * spark_in
                px = spark_x + math.cos(angle) * spread
                py = spark_y + math.sin(angle) * spread * 0.70
                radius = max(1, round((1.2 + index % 3 * 0.9) * self.art.scale))
                opacity = round((105 + (index % 4) * 22) * spark_strength)
                particle_draw.ellipse(
                    (px - radius, py - radius, px + radius, py + radius),
                    fill=(*PURPLE, opacity),
                )
                if index % 5 == 0 and radius > 1:
                    inner_radius = max(1, radius // 2)
                    particle_draw.ellipse(
                        (
                            px - inner_radius,
                            py - inner_radius,
                            px + inner_radius,
                            py + inner_radius,
                        ),
                        fill=(255, 255, 255, min(230, opacity + 45)),
                    )
            layer.alpha_composite(particles)

        for visible, opacity, blur in (
            (wheels_visible, 0.24, 8),
            (body_visible, 0.20, 9),
            (handle_visible, 0.17, 8),
        ):
            if visible.getbbox():
                glow = colored_mask(
                    visible,
                    PURPLE,
                    opacity * particle_fade,
                    max(2, blur * self.art.scale),
                )
                layer.alpha_composite(glow, (logo_x, logo_y))

        pulse_progress = phase(time_s, 1.88, 2.24, ease_in_out_cubic)
        pulse = math.sin(math.pi * pulse_progress) if 1.88 <= time_s <= 2.24 else 0.0
        if pulse > 0:
            pulse_glow = colored_mask(
                self.art.icon_and_wordmark,
                PURPLE,
                0.14 * pulse,
                max(3, 13 * self.art.scale),
            )
            layer.alpha_composite(pulse_glow, (logo_x, logo_y))
        return layer

    def render(self, time_s: float, force_final: bool = False) -> Image.Image:
        background = Image.new("RGBA", (self.width, self.height), (*WHITE, 255))
        logo_x, logo_y = self.art.logo_xy

        if force_final or time_s >= 2.50:
            background.alpha_composite(self.art.source, (logo_x, logo_y))
            return background.convert("RGB")

        wheel_progress = phase(time_s, 0.54, 1.10)
        body_progress = phase(time_s, 0.94, 1.50)
        handle_progress = phase(time_s, 1.25, 1.93)
        wordmark_progress = phase(time_s, 1.73, 2.08, ease_in_out_cubic)
        tagline_progress = phase(time_s, 2.02, 2.48)

        wheels_visible = self._wheel_reveal(wheel_progress)
        body_visible = self._horizontal_reveal(self.art.body, body_progress)
        handle_visible = self._upward_reveal(self.art.handlebar, handle_progress)
        wordmark_visible = multiply_alpha(self.art.wordmark, wordmark_progress)

        effects = self._effects_layer(
            time_s, wheels_visible, body_visible, handle_visible
        )
        background.alpha_composite(effects)

        assembled = Image.new("RGBA", self.art.logo_size, (0, 0, 0, 0))
        for mask in (wheels_visible, body_visible, handle_visible, wordmark_visible):
            assembled.alpha_composite(image_with_mask(self.art.source, mask))

        if time_s >= 2.22:
            assembled = image_with_mask(self.art.source, self.art.icon_and_wordmark)

        settle_progress = phase(time_s, 1.88, 2.24, ease_in_out_cubic)
        settle_scale = 1.02 - 0.02 * settle_progress
        settled_size = (
            round(self.art.logo_size[0] * settle_scale),
            round(self.art.logo_size[1] * settle_scale),
        )
        if settled_size != self.art.logo_size:
            assembled = assembled.resize(settled_size, Image.Resampling.LANCZOS)
        settled_x = logo_x + (self.art.logo_size[0] - assembled.width) // 2
        settled_y = logo_y + (self.art.logo_size[1] - assembled.height) // 2
        background.alpha_composite(assembled, (settled_x, settled_y))

        if tagline_progress > 0:
            tagline_mask = multiply_alpha(self.art.tagline, tagline_progress)
            tagline_layer = image_with_mask(self.art.source, tagline_mask)
            rise = round((1.0 - tagline_progress) * 12 * self.art.scale)
            background.alpha_composite(tagline_layer, (logo_x, logo_y + rise))

        return background.convert("RGB")


def make_global_palette(renderer: SplashRenderer, colors: int) -> Image.Image:
    sample_times = (0.16, 0.52, 0.92, 1.30, 1.70, 2.05, 2.32, 2.50)
    sample_width = 180
    sample_height = round(sample_width * renderer.height / renderer.width)
    strip = Image.new("RGB", (sample_width * len(sample_times), sample_height), WHITE)
    for index, time_s in enumerate(sample_times):
        sample = renderer.render(time_s, force_final=time_s >= 2.50)
        sample = sample.resize((sample_width, sample_height), Image.Resampling.LANCZOS)
        strip.paste(sample, (index * sample_width, 0))
    palette = strip.quantize(colors=colors, method=Image.Quantize.MEDIANCUT)
    # Median-cut tends to average the dominant white background to 252-254.
    # Reserve the first palette slot for literal white so every untouched
    # background pixel stays exactly RGB(255, 255, 255) in the GIF.
    palette_data = palette.getpalette()
    palette_data[0:3] = [255, 255, 255]
    palette.putpalette(palette_data)
    return palette


def render_gif(
    original: Image.Image,
    canvas_size: tuple[int, int],
    output_path: Path,
    colors: int,
) -> None:
    renderer = SplashRenderer(original, canvas_size)
    palette = make_global_palette(renderer, colors)
    frames: list[Image.Image] = []
    white_canvas = Image.new("RGB", canvas_size, WHITE)

    def quantize_frame(frame: Image.Image) -> Image.Image:
        quantized = frame.quantize(
            palette=palette, dither=Image.Dither.FLOYDSTEINBERG
        )
        difference = ImageChops.difference(frame, white_canvas).convert("L")
        exact_white_mask = difference.point(
            lambda value: 255 if value == 0 else 0
        )
        quantized.paste(0, mask=exact_white_mask)
        return quantized

    for index in range(REGULAR_FRAME_COUNT):
        time_s = index * FRAME_INTERVAL_MS / 1000.0
        frame = renderer.render(time_s)
        frames.append(quantize_frame(frame))

    final_frame = renderer.render(
        REGULAR_FRAME_COUNT * FRAME_INTERVAL_MS / 1000.0, force_final=True
    )
    frames.append(quantize_frame(final_frame))
    durations = [FRAME_INTERVAL_MS] * REGULAR_FRAME_COUNT + [FINAL_HOLD_MS]

    output_path.parent.mkdir(parents=True, exist_ok=True)
    frames[0].save(
        output_path,
        format="GIF",
        save_all=True,
        append_images=frames[1:],
        duration=durations,
        loop=0,
        optimize=False,
        disposal=1,
    )
    with Image.open(output_path) as saved:
        saved_durations = []
        for index in range(saved.n_frames):
            saved.seek(index)
            saved_durations.append(saved.info.get("duration", 0))
        saved_frame_count = saved.n_frames
    print(
        f"Wrote {output_path} | {canvas_size[0]}x{canvas_size[1]} | "
        f"{saved_frame_count} stored frames | "
        f"{sum(saved_durations) / 1000:.2f}s"
    )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--svg", type=Path, default=DEFAULT_SVG)
    parser.add_argument("--reference-dir", type=Path, default=DEFAULT_REFERENCE_DIR)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--preview", type=Path, default=DEFAULT_PREVIEW)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    validate_storyboard(args.reference_dir)
    original = extract_embedded_png(args.svg)
    render_gif(original, (1080, 1920), args.output, colors=160)
    render_gif(original, (720, 1280), args.preview, colors=128)


if __name__ == "__main__":
    main()
