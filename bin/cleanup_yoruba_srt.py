#!/usr/bin/env python3
import argparse
import json
import os
import re
import sys
import urllib.request

def parse_blocks(content):
    return re.split(r"\n\s*\n", content.strip(), flags=re.MULTILINE)

def is_timestamp(line):
    return "-->" in line

def clean_yoruba_text(text, api_key, model):
    payload = {
        "model": model,
        "input": (
            "You are a professional Yoruba subtitle editor. "
            "Correct Yoruba grammar, spelling, tone marks where appropriate, spacing, and natural phrasing. "
            "Preserve the original meaning. Do not add explanations. "
            "Return only the corrected subtitle text.\n\n"
            f"{text}"
        )
    }

    req = urllib.request.Request(
        "https://api.openai.com/v1/responses",
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {api_key}",
        },
        method="POST",
    )

    with urllib.request.urlopen(req, timeout=60) as resp:
        data = json.loads(resp.read().decode("utf-8"))

    if "output_text" in data:
        return data["output_text"].strip()

    parts = []
    for item in data.get("output", []):
        for content in item.get("content", []):
            if content.get("type") == "output_text":
                parts.append(content.get("text", ""))

    return "\n".join(parts).strip()

def clean_srt(input_path, output_path, api_key, model):
    with open(input_path, "r", encoding="utf-8", errors="replace") as f:
        content = f.read()

    blocks = parse_blocks(content)
    cleaned_blocks = []

    for block in blocks:
        lines = block.splitlines()

        if len(lines) < 3:
            cleaned_blocks.append(block)
            continue

        number = lines[0]
        timestamp = lines[1]

        if not is_timestamp(timestamp):
            cleaned_blocks.append(block)
            continue

        original_text = "\n".join(lines[2:]).strip()

        if not original_text:
            cleaned_blocks.append(block)
            continue

        cleaned_text = clean_yoruba_text(original_text, api_key, model)

        cleaned_blocks.append(f"{number}\n{timestamp}\n{cleaned_text}")

    with open(output_path, "w", encoding="utf-8") as f:
        f.write("\n\n".join(cleaned_blocks).strip() + "\n")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--model", default="gpt-4.1-mini")
    parser.add_argument("--api-key", default=os.getenv("OPENAI_API_KEY", ""))
    args = parser.parse_args()

    if not args.api_key:
        print("ERROR: Missing OpenAI API key.", file=sys.stderr)
        return 2

    clean_srt(args.input, args.output, args.api_key, args.model)
    print(f"CLEANED_YORUBA_SRT_PATH={args.output}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
