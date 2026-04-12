# Voorbereidingshandleiding Leaderboard & AI Feedback Service

Deze handleiding beschrijft de stappen om de Leaderboard-omgeving, inclusief de AI-agent en Ollama, volledig operationeel te krijgen.

## 1. Systeemvereisten en Installatie

### Git en Code
1. Open een terminal of command prompt.
2. Kloon de repository naar de gewenste locatie:
   ```bash
   git clone <repository-url>
   cd leaderboard
   ```

### Webserver & PHP
- Zorg voor een werkende PHP-omgeving (bijv. XAMPP, WAMP of een losse Apache/Nginx installatie).
- De `htdocs` map moet toegankelijk zijn via je browser.
- Controleer of de map `database/` (nodig voor SQLite) en `htdocs/artifacts` (nodig voor opdrachten) schrijfbaar is voor de webserver. Run `bin/setup.sh` om dit automatische te laten genereren.

### Python
- Installeer Python 3.10 of hoger.
- Installeer de benodigde libraries:
  ```bash
  pip install requests
  ```

## 2. Database en Applicatie Setup

1. Start je webserver.
2. Navigeer naar `http://<url>/setup.php` (pas de URL aan naar jouw omgeving).
3. Maak het eerste **Beheerdersaccount** aan. Dit initialiseert automatisch alle database-tabellen.

## 3. Ollama (AI Engine) Voorbereiden
In het leaderboard wordt ook een AI agent ondersteund die suggesties doet op basis van de chat-antwoorden die de teams geven. Deze AI-agent die draait apart van de leaderboard-webapplicatie en maakt contact met de webapplicatie via een API. Hiervoor heeft de AI-agent een API-key nodig die je in de leaderboard-webapplicatie kan aanmaken. Er kunnen meerdere AI-agents actief zijn. Je hebt hiervoor wel een GPU nodig en een geïnstalleerde Ollama applicatie. Dus om op jouw laptop, server (met GPU) of ander systeem de AI-agent te installeren op linux:

1. Download en installeer **Ollama** via ollama.com.
2. Start de Ollama applicatie.
3. Download het model dat geconfigureerd staat in `bin/config.py` (standaard `qwen3:4b` of vergelijkbaar):
   ```bash
   ollama pull qwen3:4b
   ```

Dus om op jouw laptop, server (met GPU) of ander systeem de AI-agent te installeren op Windows:
1. Download en installeer **Ollama** via ollama.com.
2. Start de Ollama applicatie.
3. Download het model dat geconfigureerd staat door in de GUI deze te downloaden. Je kan in de GUI testen of het model ook daadwerkelijk werkt. Bijvoorbeeld qwen3:4b.

## 4. AI Agent en API Configuratie

De Python AI-agent heeft een token nodig om veilig te communiceren met de web-omgeving.

1. **API Token genereren**:
   - Selecteer het menu `API Keys`.
   - Genereer een nieuwe API Key voor iedere agent apart. Vul de naam in van de AI-agent, bijvoorbeeld `AI laptop`.
2. **Configuratie aanpassen**:
   - Kopieer `bin/config.py.sample` naar `bin/config.py`.
   - Open `bin/config.py`.
   - Zet de `API_KEY` op de waarde die je zojuist in de database hebt gezet.
   - Controleer of `BASE_URL` verwijst naar de juiste `api.php` locatie.
3. **AI modellen selecteren**
   - Je kan meerdere AI modellen selecteren. De antwoorden van de verschillende modellen komen als chats binnen die overgenomen kan worden.
   - Ieder model heeft tijd nodig om een antwoord te formuleren. Één model kiezen heeft dus de voorkeur.

## 5. Specifieke Opdrachten en Darknet

1. **Darknet herstarten**: Controleer of de Darknet-omgeving (indien van toepassing) draait. Herstart deze indien nodig om een schone lei te hebben. Maak een beheerder account aan.
2. **Zebrawave Token**:
   - Genereer een nieuwe token voor het Zebrawave platform. Bijvoorbeeld met de token naam ZEBR-A234
3. **Opdracht Aanpassen**:
   - Log in als docent op het Leaderboard dashboard.
   - Ga naar het beheer van opdrachten (assignments).
   - Zoek de opdracht **"Darknet Gamers!"**.
   - Pas de tekst aan en voeg de nieuwe Zebrawave token toe aan de instructies, zodat de teams de juiste inloggegevens/toegang hebben.

## 6. De AI Agent Starten

Start de proces-monitor die de antwoorden van teams beoordeelt:
```bash
python bin/process_ai_feedback.py
```

## 7. Controle van Werking
- Controleer linksonder in het **Teacher Dashboard** of de AI-status op **"active"** staat (dit wordt ververst via de heartbeat).
- Dien een testbericht in als team om te zien of de AI-agent een suggestie terugstuurt naar de docent.