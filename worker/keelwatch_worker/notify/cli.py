"""`python -m keelwatch_worker destinations ...`: manage notification
destinations until the dashboard has a settings page.

The webhook URL is read from a hidden prompt, never from a command-line
argument (arguments end up in shell history and process listings). It is
validated, encrypted, and never printed back.
"""

from __future__ import annotations

import getpass
from collections.abc import Callable

from pymysql.connections import Connection

from .crypto import seal
from .destinations import KINDS, SEVERITIES, InvalidDestination, validate_url


class DestinationError(Exception):
    pass


def add_destination(
    conn: Connection,
    key: bytes,
    *,
    github_installation_id: int,
    kind: str,
    label: str,
    min_severity: str,
    url: str,
) -> int:
    if kind not in KINDS:
        raise DestinationError(f"kind must be one of {', '.join(KINDS)}")
    if min_severity not in SEVERITIES:
        raise DestinationError(f"min-severity must be one of {', '.join(SEVERITIES)}")
    label = label.strip()
    if not label or len(label) > 100:
        raise DestinationError("label must be 1-100 characters")
    try:
        host = validate_url(kind, url)
    except InvalidDestination as exc:
        raise DestinationError(str(exc)) from exc

    with conn.cursor() as cur:
        cur.execute(
            "SELECT id FROM installations WHERE github_installation_id = %s AND status = 'active'",
            (github_installation_id,),
        )
        row = cur.fetchone()
        if row is None:
            raise DestinationError("no active installation with that GitHub installation ID")
        cur.execute(
            "INSERT INTO notification_destinations "
            "(installation_id, kind, label, url_ciphertext, url_host, min_severity) "
            "VALUES (%s, %s, %s, %s, %s, %s)",
            (row["id"], kind, label, seal(url.strip(), key), host, min_severity),
        )
        return cur.lastrowid


def list_destinations(conn: Connection) -> list[dict]:
    with conn.cursor() as cur:
        cur.execute(
            "SELECT d.id, i.github_installation_id, i.account_login, d.kind, d.label, d.url_host, "
            "d.min_severity, d.enabled FROM notification_destinations d "
            "JOIN installations i ON i.id = d.installation_id ORDER BY d.id"
        )
        return cur.fetchall()


def disable_destination(conn: Connection, destination_id: int) -> bool:
    """Disables rather than deletes, so the delivery history stays auditable."""
    with conn.cursor() as cur:
        cur.execute(
            "UPDATE notification_destinations SET enabled = 0, updated_at = UTC_TIMESTAMP(3) WHERE id = %s",
            (destination_id,),
        )
        return cur.rowcount == 1


def prompt_url(prompt: Callable[[str], str] = getpass.getpass) -> str:
    return prompt("Webhook URL (input hidden): ").strip()
