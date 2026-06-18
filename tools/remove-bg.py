#!/opt/cmsg-bgremove/bin/python

import os
import sys
from rembg import remove
from PIL import Image

os.environ["U2NET_HOME"] = "/opt/rembg-models"

if len(sys.argv) < 3:
    print("Usage: remove-bg.py input output")
    sys.exit(1)

inp = sys.argv[1]
out = sys.argv[2]

img = Image.open(inp).convert("RGBA")
result = remove(img)
result.save(out)
print(out)
