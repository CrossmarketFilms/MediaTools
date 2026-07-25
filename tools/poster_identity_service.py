#!/usr/bin/env python3
import argparse
import importlib.util
from pathlib import Path


def load_analyzer():
    path = Path(__file__).with_name("analyze-poster-actor-sources.py")
    spec = importlib.util.spec_from_file_location("poster_actor_analyzer", path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main():
    analyzer = load_analyzer()
    parser = argparse.ArgumentParser(description="Poster identity service. Identity is the only mandatory actor gate.")
    parser.add_argument("--actor-id", default="")
    parser.add_argument("--actor-index", default=0, type=int)
    parser.add_argument("--source", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--crop-output", default="")
    args = parser.parse_args()
    analyzer.identity_anchor(args)


if __name__ == "__main__":
    main()
