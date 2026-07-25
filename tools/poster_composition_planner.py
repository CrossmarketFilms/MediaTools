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
    parser = argparse.ArgumentParser(description="Poster composition planner. Converts identity/body reports into a generation plan.")
    parser.add_argument("--brief", default="")
    parser.add_argument("--identity-report", required=True)
    parser.add_argument("--body-report", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    analyzer.composition_plan(args)


if __name__ == "__main__":
    main()
