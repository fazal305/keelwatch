import sys
from pathlib import Path

# Make both the package and the shared test helpers importable.
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
