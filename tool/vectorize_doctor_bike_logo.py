#!/usr/bin/env python3
"""Rebuild the supplied Doctor Bike raster-backed SVG as layered vector paths.

The source SVG contains an embedded transparent PNG.  This script extracts the
PNG, separates the flat artwork by color and geometry, traces each component,
and writes a Rive-friendly SVG containing only named groups and vector paths.

Runtime dependency: Pillow
Rendering validation: Google Chrome or Microsoft Edge (headless)
"""

from __future__ import annotations

import argparse
import base64
import math
import re
import shutil
import subprocess
import tempfile
import xml.etree.ElementTree as ET
from collections import defaultdict, deque
from dataclasses import dataclass
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw, ImageEnhance, ImageStat


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCE = Path(r"C:\Users\hp\Downloads\doctor_bike_logo_exact.svg")
DEFAULT_OUTPUT = PROJECT_ROOT / "assets" / "images" / "doctor_bike_logo_rive.svg"
DEFAULT_PREVIEW = (
    PROJECT_ROOT / "assets" / "images" / "doctor_bike_logo_rive_preview.png"
)
DEFAULT_COMPARISON = (
    PROJECT_ROOT / "assets" / "images" / "doctor_bike_logo_rive_comparison.png"
)

WIDTH = 821
HEIGHT = 859
NAVY = (15, 15, 49)
PURPLE = (107, 101, 189)
BLACK = (0, 0, 0)
ALPHA_THRESHOLD = 112

REQUIRED_GROUPS = (
    "rear_wheel",
    "front_wheel",
    "lightning",
    "front_arch",
    "handlebar_stem",
    "handlebar_top",
    "wordmark",
    "tagline",
)


@dataclass(frozen=True)
class TraceStyle:
    epsilon: float
    smoothing: float
    corner_angle: float


TRACE_STYLES = {
    # Values above one source pixel remove raster stair-steps while preserving
    # the supplied silhouette. Rounded parts then receive a restrained
    # quadratic smoothing pass; intentional corners stay sharp.
    "rear_wheel": TraceStyle(1.18, 0.20, 145.0),
    "front_wheel": TraceStyle(1.18, 0.20, 145.0),
    "lightning": TraceStyle(1.05, 0.0, 180.0),
    "front_arch": TraceStyle(1.12, 0.18, 145.0),
    "handlebar_stem": TraceStyle(1.05, 0.16, 142.0),
    "handlebar_top": TraceStyle(1.08, 0.18, 142.0),
    "wordmark": TraceStyle(0.82, 0.10, 150.0),
    "tagline": TraceStyle(0.68, 0.08, 152.0),
}


def extract_source(svg_path: Path) -> Image.Image:
    text = svg_path.read_text(encoding="utf-8")
    match = re.search(
        r"href\s*=\s*['\"]data:image/png;base64,([^'\"]+)['\"]", text
    )
    if not match:
        raise ValueError(f"No embedded PNG was found in {svg_path}")
    from io import BytesIO

    image = Image.open(BytesIO(base64.b64decode(match.group(1)))).convert("RGBA")
    if image.size != (WIDTH, HEIGHT):
        raise ValueError(f"Unexpected source size: {image.size}; expected {(WIDTH, HEIGHT)}")
    return image


def nearest_color(pixel: tuple[int, int, int, int]) -> str:
    red, green, blue, _ = pixel
    purple_distance = sum(
        (value - target) ** 2
        for value, target in zip((red, green, blue), PURPLE)
    )
    navy_distance = sum(
        (value - target) ** 2 for value, target in zip((red, green, blue), NAVY)
    )
    return "purple" if purple_distance < navy_distance else "navy"


def masks_from_source(source: Image.Image) -> dict[str, Image.Image]:
    pixels = source.load()
    dark_bytes = bytearray(WIDTH * HEIGHT)
    lightning_bytes = bytearray(WIDTH * HEIGHT)
    wordmark_bytes = bytearray(WIDTH * HEIGHT)
    tagline_bytes = bytearray(WIDTH * HEIGHT)

    for y in range(HEIGHT):
        offset = y * WIDTH
        for x in range(WIDTH):
            pixel = pixels[x, y]
            if pixel[3] < ALPHA_THRESHOLD:
                continue
            index = offset + x
            if y >= 760:
                tagline_bytes[index] = 255
            elif y >= 580:
                wordmark_bytes[index] = 255
            elif nearest_color(pixel) == "purple":
                lightning_bytes[index] = 255
            else:
                dark_bytes[index] = 255

    dark_mask = Image.frombytes("L", (WIDTH, HEIGHT), bytes(dark_bytes))
    components = connected_components(dark_mask, minimum_area=100)
    if len(components) != 3:
        details = [(area, mask.getbbox()) for area, mask in components]
        raise ValueError(f"Expected 3 navy icon components, got {details}")

    rear_wheel: Image.Image | None = None
    front_wheel: Image.Image | None = None
    upper: Image.Image | None = None
    for _, component in components:
        bbox = component.getbbox()
        if bbox is None:
            continue
        left, top, right, bottom = bbox
        if right < 320 and top > 300:
            rear_wheel = component
        elif left > 500 and top > 290:
            front_wheel = component
        else:
            upper = component

    if rear_wheel is None or front_wheel is None or upper is None:
        raise ValueError("Could not classify the three navy icon components")

    arch_selector = Image.new("L", (WIDTH, HEIGHT), 0)
    ImageDraw.Draw(arch_selector).rectangle((0, 235, WIDTH, HEIGHT), fill=255)
    front_arch = ImageChops.multiply(upper, arch_selector)

    stem_selector = Image.new("L", (WIDTH, HEIGHT), 0)
    ImageDraw.Draw(stem_selector).polygon(
        ((490, 48), (540, 48), (632, 286), (563, 300)), fill=255
    )
    handlebar_stem = ImageChops.multiply(upper, stem_selector)

    top_selector = Image.new("L", (WIDTH, HEIGHT), 0)
    ImageDraw.Draw(top_selector).rectangle((350, 0, 560, 225), fill=255)
    handlebar_top = ImageChops.multiply(upper, top_selector)

    assigned_upper = ImageChops.lighter(
        front_arch, ImageChops.lighter(handlebar_stem, handlebar_top)
    )
    missing_upper = ImageChops.subtract(upper, assigned_upper)
    if missing_upper.getbbox() is not None:
        # Any seam pixels belong to the stem.  This keeps the total silhouette
        # exact while retaining useful overlaps at the animation joints.
        handlebar_stem = ImageChops.lighter(handlebar_stem, missing_upper)

    return {
        "rear_wheel": rear_wheel,
        "front_wheel": front_wheel,
        "lightning": Image.frombytes(
            "L", (WIDTH, HEIGHT), bytes(lightning_bytes)
        ),
        "front_arch": front_arch,
        "handlebar_stem": handlebar_stem,
        "handlebar_top": handlebar_top,
        "wordmark": Image.frombytes("L", (WIDTH, HEIGHT), bytes(wordmark_bytes)),
        "tagline": Image.frombytes("L", (WIDTH, HEIGHT), bytes(tagline_bytes)),
    }


def connected_components(
    mask: Image.Image, minimum_area: int
) -> list[tuple[int, Image.Image]]:
    data = mask.tobytes()
    visited = bytearray(WIDTH * HEIGHT)
    components: list[tuple[int, Image.Image]] = []

    for seed in range(WIDTH * HEIGHT):
        if not data[seed] or visited[seed]:
            continue
        queue = deque([seed])
        visited[seed] = 1
        indexes: list[int] = []
        while queue:
            index = queue.popleft()
            indexes.append(index)
            x = index % WIDTH
            y = index // WIDTH
            for neighbor in (
                index - 1 if x else -1,
                index + 1 if x + 1 < WIDTH else -1,
                index - WIDTH if y else -1,
                index + WIDTH if y + 1 < HEIGHT else -1,
            ):
                if neighbor >= 0 and data[neighbor] and not visited[neighbor]:
                    visited[neighbor] = 1
                    queue.append(neighbor)
        if len(indexes) < minimum_area:
            continue
        component = bytearray(WIDTH * HEIGHT)
        for index in indexes:
            component[index] = 255
        components.append(
            (len(indexes), Image.frombytes("L", (WIDTH, HEIGHT), bytes(component)))
        )
    components.sort(key=lambda item: item[0], reverse=True)
    return components


Point = tuple[float, float]
IntPoint = tuple[int, int]


def boundary_loops(mask: Image.Image) -> list[list[IntPoint]]:
    data = mask.tobytes()
    bbox = mask.getbbox()
    if bbox is None:
        return []
    left, top, right, bottom = bbox
    edges: dict[IntPoint, list[IntPoint]] = defaultdict(list)

    def filled(x: int, y: int) -> bool:
        return 0 <= x < WIDTH and 0 <= y < HEIGHT and bool(data[y * WIDTH + x])

    def edge(start: IntPoint, end: IntPoint) -> None:
        edges[start].append(end)

    for y in range(top, bottom):
        for x in range(left, right):
            if not filled(x, y):
                continue
            if not filled(x, y - 1):
                edge((x, y), (x + 1, y))
            if not filled(x + 1, y):
                edge((x + 1, y), (x + 1, y + 1))
            if not filled(x, y + 1):
                edge((x + 1, y + 1), (x, y + 1))
            if not filled(x - 1, y):
                edge((x, y + 1), (x, y))

    direction_index = {(1, 0): 0, (0, 1): 1, (-1, 0): 2, (0, -1): 3}
    turn_priority = {1: 0, 0: 1, 3: 2, 2: 3}
    loops: list[list[IntPoint]] = []

    while edges:
        start = min(edges)
        current = start
        previous_direction: int | None = None
        loop = [start]
        for _ in range(sum(len(values) for values in edges.values()) + 1):
            candidates = edges.get(current)
            if not candidates:
                break
            if previous_direction is None or len(candidates) == 1:
                end = candidates[0]
            else:
                end = min(
                    candidates,
                    key=lambda candidate: turn_priority[
                        (
                            direction_index[
                                (candidate[0] - current[0], candidate[1] - current[1])
                            ]
                            - previous_direction
                        )
                        % 4
                    ],
                )
            candidates.remove(end)
            if not candidates:
                del edges[current]
            previous_direction = direction_index[
                (end[0] - current[0], end[1] - current[1])
            ]
            current = end
            if current == start:
                break
            loop.append(current)
        if current == start and len(loop) >= 4:
            loops.append(loop)

    return loops


def point_segment_distance(point: Point, start: Point, end: Point) -> float:
    dx = end[0] - start[0]
    dy = end[1] - start[1]
    if dx == 0 and dy == 0:
        return math.hypot(point[0] - start[0], point[1] - start[1])
    position = max(
        0.0,
        min(
            1.0,
            ((point[0] - start[0]) * dx + (point[1] - start[1]) * dy)
            / (dx * dx + dy * dy),
        ),
    )
    projection = (start[0] + position * dx, start[1] + position * dy)
    return math.hypot(point[0] - projection[0], point[1] - projection[1])


def simplify_open(points: list[Point], epsilon: float) -> list[Point]:
    if len(points) <= 2:
        return points
    maximum = 0.0
    split = 0
    for index in range(1, len(points) - 1):
        distance = point_segment_distance(points[index], points[0], points[-1])
        if distance > maximum:
            maximum = distance
            split = index
    if maximum <= epsilon:
        return [points[0], points[-1]]
    left = simplify_open(points[: split + 1], epsilon)
    right = simplify_open(points[split:], epsilon)
    return left[:-1] + right


def simplify_closed(points: list[IntPoint], epsilon: float) -> list[Point]:
    converted = [(float(x), float(y)) for x, y in points]
    if len(converted) <= 5:
        return converted
    start_index = min(
        range(len(converted)), key=lambda index: (converted[index][0], converted[index][1])
    )
    rotated = converted[start_index:] + converted[:start_index]
    split_index = max(
        range(1, len(rotated)),
        key=lambda index: (
            (rotated[index][0] - rotated[0][0]) ** 2
            + (rotated[index][1] - rotated[0][1]) ** 2
        ),
    )
    first = simplify_open(rotated[: split_index + 1], epsilon)
    second = simplify_open(rotated[split_index:] + [rotated[0]], epsilon)
    combined = first[:-1] + second[:-1]
    cleaned: list[Point] = []
    for point in combined:
        if not cleaned or point != cleaned[-1]:
            cleaned.append(point)
    return cleaned


def angle_at(previous: Point, current: Point, following: Point) -> float:
    first = (previous[0] - current[0], previous[1] - current[1])
    second = (following[0] - current[0], following[1] - current[1])
    first_length = math.hypot(*first)
    second_length = math.hypot(*second)
    if first_length == 0 or second_length == 0:
        return 0.0
    cosine = max(
        -1.0,
        min(
            1.0,
            (first[0] * second[0] + first[1] * second[1])
            / (first_length * second_length),
        ),
    )
    return math.degrees(math.acos(cosine))


def format_number(value: float) -> str:
    rounded = round(value, 2)
    if rounded == int(rounded):
        return str(int(rounded))
    return f"{rounded:.2f}".rstrip("0").rstrip(".")


def path_for_loop(points: list[Point], style: TraceStyle) -> str:
    count = len(points)
    if count < 3:
        return ""
    smooth = [
        style.smoothing > 0
        and angle_at(points[index - 1], points[index], points[(index + 1) % count])
        >= style.corner_angle
        for index in range(count)
    ]
    entries: list[Point] = []
    exits: list[Point] = []
    for index, current in enumerate(points):
        previous = points[index - 1]
        following = points[(index + 1) % count]
        if not smooth[index]:
            entries.append(current)
            exits.append(current)
            continue
        ratio = style.smoothing
        entries.append(
            (
                current[0] + (previous[0] - current[0]) * ratio,
                current[1] + (previous[1] - current[1]) * ratio,
            )
        )
        exits.append(
            (
                current[0] + (following[0] - current[0]) * ratio,
                current[1] + (following[1] - current[1]) * ratio,
            )
        )

    commands = [f"M {format_number(exits[0][0])} {format_number(exits[0][1])}"]
    for index in range(1, count + 1):
        vertex = index % count
        entry = entries[vertex]
        current = points[vertex]
        exit_point = exits[vertex]
        commands.append(f"L {format_number(entry[0])} {format_number(entry[1])}")
        if smooth[vertex]:
            commands.append(
                "Q "
                f"{format_number(current[0])} {format_number(current[1])} "
                f"{format_number(exit_point[0])} {format_number(exit_point[1])}"
            )
    commands.append("Z")
    return " ".join(commands)


def mask_to_path(mask: Image.Image, style: TraceStyle) -> str:
    loops = boundary_loops(mask)
    paths: list[str] = []
    for loop in loops:
        simplified = simplify_closed(loop, style.epsilon)
        path = path_for_loop(simplified, style)
        if path:
            paths.append(path)
    if not paths:
        raise ValueError("Mask produced no vector contours")
    return " ".join(paths)


def build_svg(masks: dict[str, Image.Image]) -> str:
    colors = {
        "rear_wheel": NAVY,
        "front_wheel": NAVY,
        "lightning": PURPLE,
        "front_arch": NAVY,
        "handlebar_stem": NAVY,
        "handlebar_top": NAVY,
        "wordmark": BLACK,
        "tagline": PURPLE,
    }
    groups = []
    for group_id in REQUIRED_GROUPS:
        path_data = mask_to_path(masks[group_id], TRACE_STYLES[group_id])
        color = "#" + "".join(f"{channel:02X}" for channel in colors[group_id])
        groups.append(
            f'  <g id="{group_id}">\n'
            f'    <path id="{group_id}_path" d="{path_data}" '
            f'fill="{color}" fill-rule="evenodd" clip-rule="evenodd"/>\n'
            "  </g>"
        )
    return (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        f'<svg xmlns="http://www.w3.org/2000/svg" width="{WIDTH}" height="{HEIGHT}" '
        f'viewBox="0 0 {WIDTH} {HEIGHT}">\n'
        "  <title>Doctor Bike layered vector logo for Rive</title>\n"
        + "\n".join(groups)
        + "\n</svg>\n"
    )


def find_browser() -> Path:
    candidates = (
        Path(r"C:\Program Files\Google\Chrome\Application\chrome.exe"),
        Path(r"C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"),
        Path(r"C:\Program Files\Microsoft\Edge\Application\msedge.exe"),
        Path(r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"),
    )
    for candidate in candidates:
        if candidate.is_file():
            return candidate
    raise FileNotFoundError("Chrome or Edge is required for SVG render validation")


def render_svg(svg_path: Path, output_path: Path) -> None:
    browser = find_browser()
    output_path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="doctor-bike-svg-") as profile:
        command = [
            str(browser),
            "--headless=new",
            "--disable-gpu",
            "--hide-scrollbars",
            "--no-first-run",
            "--force-device-scale-factor=1",
            f"--user-data-dir={profile}",
            f"--window-size={WIDTH},{HEIGHT}",
            "--default-background-color=00000000",
            f"--screenshot={output_path.resolve()}",
            svg_path.resolve().as_uri(),
        ]
        completed = subprocess.run(
            command, capture_output=True, text=True, timeout=45, check=False
        )
        if completed.returncode != 0 or not output_path.is_file():
            raise RuntimeError(
                "Headless SVG rendering failed:\n"
                + completed.stdout
                + "\n"
                + completed.stderr
            )
    with Image.open(output_path) as image:
        if image.size != (WIDTH, HEIGHT):
            raise ValueError(f"Browser rendered unexpected size: {image.size}")


def make_comparison(
    original_png: Path, vector_png: Path, comparison_path: Path
) -> tuple[float, float]:
    with Image.open(original_png) as image:
        original_rgba = image.convert("RGBA")
    with Image.open(vector_png) as image:
        vector_rgba = image.convert("RGBA")
    white = Image.new("RGBA", (WIDTH, HEIGHT), "white")
    original_white = white.copy()
    original_white.alpha_composite(original_rgba)
    vector_white = white.copy()
    vector_white.alpha_composite(vector_rgba)
    original_rgb = original_white.convert("RGB")
    vector_rgb = vector_white.convert("RGB")
    difference = ImageChops.difference(original_rgb, vector_rgb)
    mean_absolute_error = sum(ImageStat.Stat(difference).mean) / 3.0
    differing_pixels = sum(
        1
            for red, green, blue in difference.get_flattened_data()
        if max(red, green, blue) > 18
    )
    differing_percent = differing_pixels * 100.0 / (WIDTH * HEIGHT)

    enhanced_difference = ImageEnhance.Contrast(difference).enhance(2.0)
    overlay = Image.blend(original_rgb, vector_rgb, 0.5)
    header = 36
    sheet = Image.new("RGB", (WIDTH * 4, HEIGHT + header), "white")
    draw = ImageDraw.Draw(sheet)
    labels = ("Original", "Vector", "50% overlay", "Difference x2")
    panels = (original_rgb, vector_rgb, overlay, enhanced_difference)
    for index, (label, panel) in enumerate(zip(labels, panels)):
        x = index * WIDTH
        draw.text((x + 12, 10), label, fill=BLACK)
        sheet.paste(panel, (x, header))
    comparison_path.parent.mkdir(parents=True, exist_ok=True)
    sheet.save(comparison_path)
    return mean_absolute_error, differing_percent


def validate_svg(svg_path: Path, preview_path: Path) -> dict[str, object]:
    svg_text = svg_path.read_text(encoding="utf-8")
    root = ET.fromstring(svg_text)
    paths = [element for element in root.iter() if element.tag.endswith("path")]
    images = [element for element in root.iter() if element.tag.endswith("image")]
    group_ids = {
        element.attrib.get("id")
        for element in root.iter()
        if element.tag.endswith("g")
    }
    missing = [group_id for group_id in REQUIRED_GROUPS if group_id not in group_ids]
    if not paths:
        raise ValueError("Generated SVG contains no paths")
    if images:
        raise ValueError("Generated SVG unexpectedly contains image elements")
    if "base64" in svg_text.lower() or "data:image" in svg_text.lower():
        raise ValueError("Generated SVG unexpectedly contains raster data")
    if missing:
        raise ValueError(f"Generated SVG is missing groups: {missing}")
    with Image.open(preview_path) as preview:
        rgba = preview.convert("RGBA")
        transparent_corners = all(
            rgba.getpixel(point)[3] == 0
            for point in (
                (0, 0),
                (WIDTH - 1, 0),
                (0, HEIGHT - 1),
                (WIDTH - 1, HEIGHT - 1),
            )
        )
    if not transparent_corners:
        raise ValueError("Rendered vector preview did not preserve transparency")
    return {
        "paths": len(paths),
        "images": len(images),
        "groups": sorted(group_id for group_id in group_ids if group_id),
        "transparent": transparent_corners,
    }


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--source", type=Path, default=DEFAULT_SOURCE)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--preview", type=Path, default=DEFAULT_PREVIEW)
    parser.add_argument("--comparison", type=Path, default=DEFAULT_COMPARISON)
    return parser.parse_args()


def main() -> None:
    args = parse_args()
    source = extract_source(args.source)
    masks = masks_from_source(source)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(build_svg(masks), encoding="utf-8", newline="\n")

    with tempfile.TemporaryDirectory(prefix="doctor-bike-vector-compare-") as temp:
        original_png = Path(temp) / "original.png"
        render_svg(args.source, original_png)
        render_svg(args.output, args.preview)
        mean_error, differing_percent = make_comparison(
            original_png, args.preview, args.comparison
        )
    validation = validate_svg(args.output, args.preview)

    print(f"SVG: {args.output}")
    print(f"Preview: {args.preview}")
    print(f"Comparison: {args.comparison}")
    print(f"Paths: {validation['paths']} | Images: {validation['images']}")
    print(f"Groups: {', '.join(validation['groups'])}")
    print(f"Transparent: {validation['transparent']}")
    print(
        f"Visual MAE: {mean_error:.4f}/255 | "
        f"pixels over threshold: {differing_percent:.3f}%"
    )


if __name__ == "__main__":
    main()
