"""MySQL connection factory. The API owns the schema; the worker only reads
and writes rows."""

from __future__ import annotations

import pymysql
from pymysql.connections import Connection

from .config import Settings


def connect(settings: Settings) -> Connection:
    return pymysql.connect(
        host=settings.db_host,
        port=settings.db_port,
        user=settings.db_user,
        password=settings.db_password,
        database=settings.db_name,
        connect_timeout=settings.db_connect_timeout_s,
        read_timeout=30,
        write_timeout=30,
        charset="utf8mb4",
        autocommit=True,
        # All timestamps are stored and compared in UTC, by every service.
        init_command="SET time_zone = '+00:00'",
        cursorclass=pymysql.cursors.DictCursor,
    )
