# Fixtures

- `legacy_v1.tar.gz`: gemaakt met de **eerste versie** van `bin/export_assignments.py` (commit `c249ede`,
  vóór spellen en AI): manifest is een lijst met 7 velden per opdracht.
- `legacy_v2.tar.gz`: gemaakt met `bin/export_assignments.py --game 1` zoals het was vóór de worker
  (commit `87ecf58`): lijst met daarbij `ai_rubric` (JSON-tekst), `ai_rubric_status` en `ai_auto_allowed`.

Beide bevatten twee opdrachten; opdracht 1 heeft de bijlage `files/assignment_1_bijlage.txt`, en in v2 een
goedgekeurde rubric. Opnieuw maken (vanuit de repo-root):

```sh
python3 worker-service/tests/fixtures/make_legacy.py
```

Het script haalt de oude exportscripts met `git show` op, maakt een kleine database met het schema van
toen en draait ze. De worker leest deze archieven in `worker/formats/legacy.py`.
