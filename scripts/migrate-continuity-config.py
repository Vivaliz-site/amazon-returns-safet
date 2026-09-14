from __future__ import annotations

import json
import os
from pathlib import Path
import stat
import sys

LEGACY_PATH = "/home/ubuntu/amazon-returns-deploy-source"
MOUNT_PATH = "/srv/continuity/repositories/amazon-returns-safet"
REPOSITORY = "Vivaliz-site/amazon-returns-safet"
HOST = "shopvivaliz-free-a1"


def migrate(path: Path) -> bool:
    path = Path(path)
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("auto_dispatch") is not False:
        return False

    changed = False
    for repo in data.get("repositories", []):
        if (
            repo.get("repository") == REPOSITORY
            and repo.get("host") == HOST
            and repo.get("path") == LEGACY_PATH
            and repo.get("github_enabled") is False
        ):
            repo["path"] = MOUNT_PATH
            changed = True

    if not changed:
        return False

    st = path.stat()
    tmp = path.with_name(path.name + ".tmp")
    tmp.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    os.chmod(tmp, stat.S_IMODE(st.st_mode))
    if os.geteuid() == 0:
        os.chown(tmp, st.st_uid, st.st_gid)
    os.replace(tmp, path)
    return True


def main() -> int:
    if len(sys.argv) != 2:
        raise SystemExit("usage: migrate-continuity-config.py CONFIG")
    return 10 if migrate(Path(sys.argv[1])) else 0


if __name__ == "__main__":
    raise SystemExit(main())
