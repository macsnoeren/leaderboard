"""T2.10: LLMClient.chat_json met format, think, stream=false, validatie en één retry."""
import json
import unittest
from unittest import mock

import requests

from agentic import schemas
from agentic.llm import LLMClient, LLMError, parse_json

VALID = {"intentie": "vraag", "zeker": True, "toelichting": "ok"}


def response(status=200, content=None, thinking="denk"):
    r = mock.Mock()
    r.status_code = status
    r.text = "fout"
    r.json.return_value = {"message": {"content": content if isinstance(content, str) else json.dumps(content), "thinking": thinking}}
    return r


class LLMTest(unittest.TestCase):
    def client(self, *responses):
        session = mock.Mock()
        session.post.side_effect = list(responses)
        return LLMClient("http://ollama:11434/api/chat", timeout=5, session=session, sleep=lambda s: None), session

    def test_valid_response(self):
        llm, session = self.client(response(content=VALID))
        obj, thinking, duration = llm.chat_json("gpt-oss:20b-cloud", "low", "sys", "user", schemas.INTENT)
        self.assertEqual(obj, VALID)
        self.assertEqual(thinking, "denk")
        self.assertIsInstance(duration, int)
        body = session.post.call_args.kwargs["json"]
        self.assertEqual(session.post.call_args.args[0], "http://ollama:11434/api/chat")
        self.assertEqual(body["format"], schemas.INTENT)
        self.assertEqual(body["think"], "low")
        self.assertIs(body["stream"], False)
        self.assertIn("JSON-schema", body["messages"][0]["content"])  # schema ook in de prompt (T0.3)
        self.assertTrue(llm.last_ok)

    def test_code_block_is_parsed(self):
        llm, _ = self.client(response(content="```json\n" + json.dumps(VALID) + "\n```"))
        self.assertEqual(llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)[0], VALID)

    def test_invalid_then_valid(self):
        bad = {"intentie": "antwoord (gok)", "zeker": "hoog", "toelichting": "x"}
        llm, session = self.client(response(content=bad), response(content=VALID))
        self.assertEqual(llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)[0], VALID)
        self.assertEqual(session.post.call_count, 2)
        # De tweede poging krijgt de fout mee
        self.assertIn("ongeldig", session.post.call_args.kwargs["json"]["messages"][-1]["content"])

    def test_twice_invalid_raises(self):
        llm, session = self.client(response(content="geen json"), response(content="nog steeds niet"))
        with self.assertRaises(LLMError):
            llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)
        self.assertEqual(session.post.call_count, 2)
        self.assertFalse(llm.last_ok)

    def test_429_retry(self):
        llm, session = self.client(response(status=429), response(content=VALID))
        self.assertEqual(llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)[0], VALID)
        self.assertEqual(session.post.call_count, 2)

    def test_timeout_twice_raises(self):
        llm, session = self.client(requests.Timeout(), requests.Timeout())
        with self.assertRaises(LLMError):
            llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)
        self.assertEqual(session.post.call_count, 2)

    def test_404_no_retry(self):
        llm, session = self.client(response(status=404))
        with self.assertRaises(LLMError):
            llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)
        self.assertEqual(session.post.call_count, 1)

    def test_last_ok_follows_calls(self):
        llm, _ = self.client(response(content=VALID), response(status=500), response(status=503), response(content=VALID))
        self.assertIsNone(llm.last_ok)
        llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)
        self.assertTrue(llm.last_ok)
        with self.assertRaises(LLMError):
            llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)
        self.assertFalse(llm.last_ok)
        llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT)
        self.assertTrue(llm.last_ok)

    def test_check_failure_counts_as_invalid(self):
        llm, _ = self.client(response(content=VALID), response(content=VALID))
        with self.assertRaises(LLMError):
            llm.chat_json("gpt-oss:20b-cloud", "low", "s", "u", schemas.INTENT, check=lambda o: "altijd fout")

    def test_think_omitted_when_empty_and_generate_url(self):
        session = mock.Mock()
        session.post.return_value = response(content=VALID)
        llm = LLMClient("http://ollama:11434/api/generate", session=session)
        llm.chat_json("kimi-k2.6:cloud", "", "s", "u", schemas.INTENT)
        self.assertNotIn("think", session.post.call_args.kwargs["json"])
        self.assertEqual(llm.url, "http://ollama:11434/api/chat")

    def test_local_model_refused(self):
        llm, session = self.client(response(content=VALID))
        with self.assertRaises(LLMError):
            llm.chat_json("qwen2.5:1.5b", "low", "s", "u", schemas.INTENT)
        session.post.assert_not_called()
        self.assertFalse(llm.last_ok)

    def test_parse_json(self):
        self.assertEqual(parse_json('Hier: {"a": 1} klaar'), {"a": 1})
        with self.assertRaises(ValueError):
            parse_json("[1, 2]")


if __name__ == "__main__":
    unittest.main()
