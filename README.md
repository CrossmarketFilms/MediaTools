# Crossmarket Creative Studio Core v3.0.1

Unified media workflow plugin for Crossmarket Films.

## Included studios

### Subtitle Studio
- Browser upload
- Google Drive import
- Google Cloud / large file upload
- SRT generation
- VTT closed-caption generation
- Lightweight cue detection
- Runtime verification support
- PayPal-gated processing and downloads

### Poster Studio
- Structured poster brief
- Watermarked preview concepts
- PayPal-gated finalization and downloads

### Trailer Studio v3.0.1
- Structured trailer brief form
- Genre, tone, audience, runtime, music style, CTA
- Required scenes/elements field
- Text-card field
- Asset links/editor notes field
- Paid trailer brief package generation
- Deliverables:
  - trailer-brief.json
  - trailer-edit-plan.txt
  - trailer-beat-map.csv

## Shortcodes

```text
[cm_media_tools_dashboard]
[cm_subtitle_generator]
[cm_poster_studio]
[cm_trailer_studio]
[cm_service_hub]
```

## Notes

v3.0.1 is built from the stabilized v2.8.7 baseline. The trailer workflow now stores structured creative intent and uses that structure to create trailer edit deliverables, rather than ignoring descriptions and required elements.
