# Image Editor

Edit images straight from the file manager, without leaving the admin.

## What it does

An **Edit image** button appears in the file manager for JPG, PNG, WebP and GIF files. It opens a full-screen editor with these tools:

- **Crop** - free or fixed ratios, or the original ratio.
- **Rotate** - 90° left or right, flip horizontally or vertically.
- **Resize** - set width and height, with an option to keep proportions.
- **Adjust** - brightness, contrast, saturation, warmth, blur and sharpen.
- **Filters** - black and white, sepia, vintage, cool, warm, invert, fade and dramatic.
- **Annotate** - text, rectangles, ellipses, lines, arrows and freehand pen, with colour, stroke, fill and opacity, all selectable and movable afterwards.
- **Watermark** - text or image, positioned or tiled, with size and opacity.

Undo and redo, a reset, and a "hold to compare" button with the original are always available. Escape closes the editor and asks before discarding changes.

## Saving

The result can replace the original (same format only) or be saved as a copy, in PNG, JPEG or WebP (WebP only when the server supports it), with a quality setting. A copy never overwrites an existing file: a number is added to the name when needed. If thumbnails are enabled in the site settings, the thumbnail is regenerated.

## How it works

The image is edited in the browser on a canvas; nothing is sent to the server until you save. On save, the edited image is uploaded to the file manager through the `admin_file_manager_action` filter (action `ie_save`), and the plugin adds its script and styles through `admin_file_manager_footer`. The core is not modified.

## Requirements

Synaptik CMS 1.4.8 or later (the file manager hooks), and the PHP GD extension.

## Security

Saving requires the admin session and a CSRF token. The folder is resolved against the uploads directory and refused if it escapes it, the original file must be an editable image type, and the uploaded result is checked as a real PNG, JPEG or WebP image, with limits of 40 MB and 100 million pixels. Files are written to a temporary file then renamed, so a failed save never leaves a half-written image.

## Languages

English, French and Spanish, following the admin language. Strings are in `lang/<locale>.json`.
