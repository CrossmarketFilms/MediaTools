#!/usr/bin/env python3
import argparse
import json
import os
import re
import sys
import urllib.request

LANG_NAMES = {
    "en": "English",
    "es": "Spanish",
    "pt": "Portuguese",
    "fr": "French",
    "ig": "Igbo",
    "yo": "Yoruba",
    "ha": "Hausa",
    "sw": "Swahili",
    "zh": "Mandarin Chinese",
}

def parse_srt_blocks(content):
    return re.split(r"\n\s*\n", content.strip(), flags=re.MULTILINE)

def is_timestamp(line):
    return "-->" in line

def translate_text(text, target_lang, api_key, model):
    target_name = LANG_NAMES.get(target_lang, target_lang)

    payload = {
        "model": model,
        "input": (
            f"Translate the following subtitle text into {target_name}. "
            "Return only the translated text. Do not add explanations.\n\n"
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

    # Fallback parser for nested response output
    parts = []
    for item in data.get("output", []):
        for content in item.get("content", []):
            if content.get("type") == "output_text":
                parts.append(content.get("text", ""))

    return "\n".join(parts).strip()

def translate_srt(input_path, output_path, target_lang, api_key, model):
    with open(input_path, "r", encoding="utf-8", errors="replace") as f:
        content = f.read()

    blocks = parse_srt_blocks(content)
    translated_blocks = []

    for block in blocks:
        lines = block.splitlines()
        if len(lines) < 3:
            translated_blocks.append(block)
            continue

        number = lines[0]
        timestamp = lines[1]

        if not is_timestamp(timestamp):
            translated_blocks.append(block)
            continue

        text_lines = lines[2:]
        original_text = "\n".join(text_lines).strip()

        if not original_text:
            translated_blocks.append(block)
            continue

        translated_text = translate_text(original_text, target_lang, api_key, model)

        translated_blocks.append(
            f"{number}\n{timestamp}\n{translated_text}"
        )

    with open(output_path, "w", encoding="utf-8") as f:
        f.write("\n\n".join(translated_blocks).strip() + "\n")

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--target", required=True)
    parser.add_argument("--model", default="gpt-4.1-mini")
    parser.add_argument("--api-key", default=os.getenv("OPENAI_API_KEY", ""))
    args = parser.parse_args()

    if not args.api_key:
        print("ERROR: Missing OpenAI API key.", file=sys.stderr)
        return 2

    translate_srt(args.input, args.output, args.target, args.api_key, args.model)

    print(f"TRANSLATED_SRT_PATH={args.output}")
    return 0

if __name__ == "__main__":
    sys.exit(main())
