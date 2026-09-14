from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import tempfile


class GitFixture:
    def __init__(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        self.remote = root / "remote.git"
        self.repo = root / "repo"
        subprocess.run(["git", "init", "--bare", str(self.remote)], check=True, capture_output=True, text=True)
        subprocess.run(["git", "init", "-b", "main", str(self.repo)], check=True, capture_output=True, text=True)
        self.git("config", "user.email", "continuity@example.invalid")
        self.git("config", "user.name", "Continuity Test")
        self.git("remote", "add", "origin", str(self.remote))
        self.write("tracked.txt", "base\n")
        self.git("add", "tracked.txt")
        self.git("commit", "-m", "base")
        self.git("push", "-u", "origin", "main")

    def close(self):
        self.tmp.cleanup()

    def git(self, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        return subprocess.run(["git", *args], cwd=self.repo, check=check, text=True, capture_output=True)

    def write(self, relative: str, content: str):
        path = self.repo / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def commit(self, message: str = "change"):
        self.git("add", "-A")
        self.git("commit", "-m", message)

    def create_marker(self, marker: str):
        marker_path = self.git("rev-parse", "--git-path", marker).stdout.strip()
        path = Path(marker_path)
        if not path.is_absolute():
            path = self.repo / path
        if marker.endswith(("rebase-merge", "rebase-apply")):
            path.mkdir(parents=True, exist_ok=True)
        else:
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text("fixture\n", encoding="utf-8")
        return path
