import threading

from helpers import memory_logger, settings

from keelwatch_worker.heartbeat import HeartbeatLoop


class FakeStore:
    """Records calls; fails the next `fail_next` beats; stops the loop after
    `stop_after` successful beats so tests never sleep."""

    def __init__(self, stop: threading.Event, stop_after: int, fail_next: int = 0) -> None:
        self.stop = stop
        self.stop_after = stop_after
        self.fail_next = fail_next
        self.beats: list[tuple[str, int, str]] = []
        self.stopped: list[str] = []

    def beat(self, worker_id, pid, version, started_at):
        if self.fail_next:
            self.fail_next -= 1
            raise ConnectionError("db down")
        self.beats.append((worker_id, pid, version))
        if len(self.beats) >= self.stop_after:
            self.stop.set()

    def mark_stopped(self, worker_id):
        self.stopped.append(worker_id)


class InstantEvent(threading.Event):
    """An Event whose wait() records the requested delay instead of sleeping."""

    def __init__(self) -> None:
        super().__init__()
        self.waits: list[float] = []

    def wait(self, timeout=None):
        self.waits.append(timeout)
        return self.is_set()


def make_loop(store_kwargs, **settings_overrides):
    stop = InstantEvent()
    store = FakeStore(stop, **store_kwargs)
    logger, stream = memory_logger()
    loop = HeartbeatLoop(store, settings(**settings_overrides), logger, stop, pid=4242)
    return loop, store, stop, stream


def test_beats_on_interval_then_records_stop():
    loop, store, stop, _ = make_loop({"stop_after": 3})
    loop.run()

    assert store.beats == [("test-worker", 4242, "9.9.9")] * 3
    assert store.stopped == ["test-worker"]
    assert stop.waits == [10.0, 10.0, 10.0]


def test_database_outage_backs_off_exponentially_then_recovers():
    loop, store, stop, stream = make_loop({"stop_after": 1, "fail_next": 4})
    loop.run()

    assert loop.failures == 4
    assert stop.waits == [10.0, 20.0, 40.0, 60.0, 10.0]
    assert store.stopped == ["test-worker"]
    assert '"heartbeat recovered"' in stream.getvalue()


def test_backoff_is_capped():
    loop, *_ = make_loop({"stop_after": 1})
    assert loop.backoff(1) == 10.0
    assert loop.backoff(20) == 60.0


def test_stop_before_first_beat_still_marks_stopped():
    stop = InstantEvent()
    stop.set()
    store = FakeStore(stop, stop_after=1)
    logger, _ = memory_logger()
    HeartbeatLoop(store, settings(), logger, stop, pid=1).run()

    assert store.beats == []
    assert store.stopped == ["test-worker"]


def test_failure_to_record_stop_is_logged_not_raised():
    class BrokenStop(FakeStore):
        def mark_stopped(self, worker_id):
            raise ConnectionError("gone")

    stop = InstantEvent()
    store = BrokenStop(stop, stop_after=1)
    logger, stream = memory_logger()
    HeartbeatLoop(store, settings(), logger, stop, pid=1).run()

    assert '"could not record worker stop"' in stream.getvalue()
