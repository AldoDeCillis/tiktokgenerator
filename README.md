# Tutorial Ovvi Reels - Proof of Concept

## Descrizione del Progetto
Questo progetto è un Proof of Concept (POC) di un’applicazione backend Laravel integrata con n8n, in grado di:

1. Generare automaticamente testi/script di “tutorial ovvi” tramite un modello LLM (OpenAI).
2. Creare un video (reel) basico a partire dallo script generato.
3. Pubblicare (o simulare la pubblicazione) dei reel su piattaforme social (ad es. Instagram Reels).
4. Offrire un’interfaccia locale per test, monitoraggio e debugging end-to-end.

L’idea è avere un flusso completamente automatizzato:
```
Laravel (API) → n8n (Workflow) → OpenAI (LLM) → n8n → Laravel → FFmpeg (video) → n8n → API Social → Laravel
```

---

## Indice
1. [Prerequisiti](#prerequisiti)  
2. [Struttura del Progetto](#struttura-del-progetto)  
3. [Installazione e Configurazione](#installazione-e-configurazione)  
4. [Dettagli Architetturali](#dettagli-architetturali)  
   - [Database e Model “Reel”](#database-e-model-reel)  
   - [API Laravel](#api-laravel)  
   - [Workflow n8n](#workflow-n8n)  
   - [Generazione Video](#generazione-video)  
   - [Pubblicazione Social](#pubblicazione-social)  
5. [Esecuzione e Testing](#esecuzione-e-testing)  
6. [Debug e Monitoraggio](#debug-e-monitoraggio)  
7. [Possibili Estensioni Future](#possibili-estensioni-future)  
8. [Riferimenti Utili](#riferimenti-utili)

---

## Prerequisiti

Assicurati di avere installato:
- **PHP ≥ 8.1**  
- **Composer**  
- **Node.js + npm** (per eseguire n8n)  
- **FFmpeg** (per la generazione video in locale)  
- **Docker** (opzionale ma consigliato per isolare n8n e DB)  

Chiavi API necessarie:
- **OPENAI_API_KEY** (per chiamare l’API di OpenAI)
- (Facoltative) Credenziali per API Social (Instagram Graph API, TikTok, ecc.)

---

## Struttura del Progetto

```text
tutorial-ovvi-reels/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── ReelController.php
│   ├── Models/
│   │   └── Reel.php
│   └── ... (altre cartelle Laravel)
├── database/
│   ├── migrations/
│   │   └── 2025_05_31_000000_create_reels_table.php
│   └── seeders/
├── routes/
│   ├── api.php
│   └── web.php
├── storage/
│   └── app/
│       └── public/
│           └── reels/         ← qui verranno salvati i file video
├── n8n-workflows/
│   ├── genera_script.json    ← esportazione del workflow n8n per generare script
│   ├── crea_video.json       ← esportazione del workflow n8n per creare video
│   └── pubblica_social.json  ← esportazione del workflow n8n per pubblicare social
├── .env
├── composer.json
├── package.json
├── README.md
└── ...
```

---

## Installazione e Configurazione

### 1. Clona il repository
```bash
git clone https://tuo-repo-git/tuto-ovvi-reels.git
cd tuto-ovvi-reels
```

### 2. Installa dipendenze Laravel
```bash
composer install
```

### 3. Configura il file `.env`
Copia il file di ambiente di esempio e personalizzalo:
```bash
cp .env.example .env
```
Apri `.env` e imposta:
```
APP_NAME="TutorialOvviReels"
APP_ENV=local
APP_KEY=base64:GENERARE_CON_comando_artisan
APP_DEBUG=true
APP_URL=http://localhost:8000

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tutorial_ovvi_reels
DB_USERNAME=root
DB_PASSWORD=secret

# OpenAI
OPENAI_API_KEY=tuachiave_openai

# n8n
N8N_WEBHOOK_URL=http://localhost:5678/webhook-tutorial

# (Opzionale) Credenziali Social
# INSTAGRAM_USER_ID=...
# INSTAGRAM_ACCESS_TOKEN=...
```
- Genera la chiave dell’app:  
  ```bash
  php artisan key:generate
  ```
- Crea il database MySQL `tutorial_ovvi_reels` (o SQLite, a seconda delle tue esigenze).

### 4. Esegui le migration
```bash
php artisan migrate
```

### 5. Collega il file `n8n-workflows/` a n8n
1. Avvia n8n (in locale) con Docker oppure npm:
   ```bash
   # Con Docker
   docker run -d      --name n8n      -p 5678:5678      -v ~/.n8n:/home/node/.n8n      n8nio/n8n

   # Oppure con npm (se installato globalmente)
   n8n start
   ```
2. Apri nel browser `http://localhost:5678`.
3. Importa i tre workflow JSON (dal menu “Import” in alto a destra) e salvali con nomi descrittivi:
   - **Genera Script** (`genera_script.json`)
   - **Crea Video** (`crea_video.json`)
   - **Pubblica Social** (`pubblica_social.json`)
4. Controlla che in ciascun workflow i nodi “Callback Laravel” puntino correttamente a:  
   ```
   http://localhost:8000/api/reels/{{ $json["reel_id"] }}/status
   ```
   e che abbiano le credenziali OpenAI configurate.

---

## Dettagli Architetturali

### Database e Model “Reel”
- **Migration** (`2025_05_31_000000_create_reels_table.php`):
  ```php
  <?php

  use Illuminate\Database\Migrations\Migration;
  use Illuminate\Database\Schema\Blueprint;
  use Illuminate\Support\Facades\Schema;

  class CreateReelsTable extends Migration
  {
      public function up()
      {
          Schema::create('reels', function (Blueprint $table) {
              $table->id();
              $table->string('argomento');
              $table->text('script')->nullable();
              $table->string('video_path')->nullable();
              $table->string('social_post_id')->nullable();
              $table->enum('status', ['pending', 'script_generated', 'video_ready', 'publishing', 'published', 'error'])
                    ->default('pending');
              $table->text('error_message')->nullable();
              $table->timestamps();
          });
      }

      public function down()
      {
          Schema::dropIfExists('reels');
      }
  }
  ```
- **Model** (`app/Models/Reel.php`):
  ```php
  <?php

  namespace App\Models;

  use Illuminate\Database\Eloquent\Factories\HasFactory;
  use Illuminate\Database\Eloquent\Model;

  class Reel extends Model
  {
      use HasFactory;

      protected $fillable = [
          'argomento',
          'script',
          'video_path',
          'social_post_id',
          'status',
          'error_message',
      ];
  }
  ```

### API Laravel

#### Rotte (routes/api.php)
```php
use App\Http\Controllers\ReelController;

Route::post('/reels', [ReelController::class, 'create']);
Route::post('/reels/{id}/status', [ReelController::class, 'updateStatus']);
Route::get('/reels/{id}', [ReelController::class, 'getReel']);
Route::get('/reels', [ReelController::class, 'index']);

// Endpoint per generare video (chiamato da n8n)
Route::post('/reels/{id}/create-video', [ReelController::class, 'createVideo']);
```

#### Controller (`app/Http/Controllers/ReelController.php`)
```php
<?php

namespace App\Http\Controllers;

use App\Models\Reel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ReelController extends Controller
{
    // 1. Crea un nuovo reel e invia richiesta a n8n
    public function create(Request $request)
    {
        $request->validate(['argomento' => 'required|string']);
        $reel = Reel::create([
            'argomento' => $request->argomento,
            'status'    => 'pending',
        ]);

        // Invia a n8n per generare script
        try {
            $response = Http::post(env('N8N_WEBHOOK_URL'), [
                'reel_id'  => $reel->id,
                'argomento'=> $reel->argomento,
            ]);
            if ($response->successful()) {
                return response()->json(['id' => $reel->id], 201);
            } else {
                $reel->update(['status' => 'error', 'error_message' => 'Errore invio a n8n']);
                return response()->json(['error' => 'Impossibile avviare workflow'], 500);
            }
        } catch (\Exception $e) {
            $reel->update(['status' => 'error', 'error_message' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // 2. Endpoint per aggiornare lo stato (richiamato da n8n)
    public function updateStatus($id, Request $request)
    {
        $reel = Reel::findOrFail($id);
        $status = $request->status;

        switch ($status) {
            case 'script_generated':
                $reel->update([
                    'status' => 'script_generated',
                    'script' => $request->script ?? null,
                ]);
                break;

            case 'video_ready':
                $reel->update([
                    'status' => 'video_ready',
                    'video_path' => $request->video_path ?? null,
                ]);
                break;

            case 'published':
                $reel->update([
                    'status' => 'published',
                    'social_post_id' => $request->social_post_id ?? null,
                ]);
                break;

            case 'error':
                $reel->update([
                    'status' => 'error',
                    'error_message' => $request->error_message ?? 'Unknown error',
                ]);
                break;

            default:
                return response()->json(['error' => 'Stato non riconosciuto'], 400);
        }

        return response()->json(['message' => 'Stato aggiornato']);
    }

    // 3. Visualizza un reel (stato e dettagli)
    public function getReel($id)
    {
        $reel = Reel::findOrFail($id);
        return response()->json($reel);
    }

    // 4. Elenca tutti i reel (utile per debug)
    public function index()
    {
        return response()->json(Reel::orderBy('created_at', 'desc')->get());
    }

    // 5. Genera video con FFmpeg (chiamato da n8n)
    public function createVideo($id, Request $request)
    {
        $reel = Reel::findOrFail($id);

        if (!$reel->script) {
            return response()->json(['error' => 'Script non generato'], 400);
        }

        // Percorsi
        $tmpTextPath = resource_path("tmp/reel_{$reel->id}.txt");
        $outputVideo = storage_path("app/public/reels/reel_{$reel->id}.mp4");

        // Salva script in file temporaneo
        file_put_contents($tmpTextPath, $reel->script);

        // Costruzione comando FFmpeg (esempio base, 10 secondi, sfondo bianco)
        $cmd = "ffmpeg -y -f lavfi -i color=size=720x1280:duration=10:color=white "
             . "-vf \"drawtext=fontfile=/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf:"
             . "textfile='{$tmpTextPath}':reload=1:fontcolor=black:fontsize=48:"
             . "x=(w-text_w)/2:y=(h-text_h)/2\" "
             . "-c:v libx264 -t 10 -pix_fmt yuv420p '{$outputVideo}'";

        // Esegui comando
        exec($cmd . " 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            $reel->update([
                'status' => 'error',
                'error_message' => implode("\n", $output)
            ]);
            return response()->json(['error' => 'Errore generazione video', 'details' => $output], 500);
        }

        // Aggiorna record
        $reel->update([
            'status'     => 'video_ready',
            'video_path' => "storage/reels/reel_{$reel->id}.mp4",
        ]);

        return response()->json([
            'message'    => 'Video generato con successo',
            'video_path' => $reel->video_path
        ]);
    }
}
```

---

### Workflow n8n

#### 1. Workflow “Genera Script” (`genera_script.json`)
1. **Nodo Webhook**  
   - Metodo: POST  
   - Path: `/webhook-tutorial`  
   - Parametri in ingresso: `reel_id`, `argomento`  

2. **Nodo HTTP Request → OpenAI**  
   - URL: `https://api.openai.com/v1/chat/completions`  
   - Metodo: POST  
   - Header:  
     ```
     Authorization: Bearer {{ $credentials.openai?.apiKey }}
     Content-Type: application/json
     ```  
   - Body:  
     ```json
     {
       "model": "gpt-4o-mini",
       "messages": [
         { "role": "system", "content": "Sei un generatore di script per reel tutorial ovvi." },
         { "role": "user", "content": "Genera uno script per un reel di massimo 30 secondi sul tema: {{ $json.argomento }}. Lo stile deve essere divertente e ovvio, con introduzione, 3 step, chiusura spiritosa." }
       ],
       "max_tokens": 250
     }
     ```
3. **Nodo Function (estrazione script)**  
   - Codice:
     ```js
     return {
       json: {
         reel_id: $json["reel_id"],
         script: $json["choices"][0]["message"]["content"]
       }
     };
     ```
4. **Nodo HTTP Request → Callback a Laravel**  
   - URL: `http://host.docker.internal:8000/api/reels/{{ $json.reel_id }}/status`  
     > Se Laravel è in esecuzione con `php artisan serve` sulla macchina host, n8n in Docker usa `host.docker.internal`.  
   - Metodo: POST  
   - Body:
     ```json
     {
       "status": "script_generated",
       "script": "{{ $json.script }}"
     }
     ```
5. **Salva e attiva il workflow**.

#### 2. Workflow “Crea Video” (`crea_video.json`)
1. **Nodo Webhook**  
   - Metodo: POST  
   - Path: `/webhook-crea-video`  
   - Parametri: `reel_id`, `script` (inviati da Laravel quando status = “script_generated”)
2. **Nodo HTTP Request**  
   - URL: `http://host.docker.internal:8000/api/reels/{{ $json.reel_id }}/create-video`  
   - Metodo: POST  
   - Body:
     ```json
     {
       "script": "{{ $json.script }}"
     }
     ```
3. **Nodo HTTP Request (Callback)**  
   - Dopo che Laravel restituisce `video_path`, n8n invia:
   - URL: `http://host.docker.internal:8000/api/reels/{{ $json.reel_id }}/status`  
   - Metodo: POST  
   - Body:
     ```json
     {
       "status": "video_ready",
       "video_path": "{{ $json.video_path }}"
     }
     ```
4. **Salva e attiva il workflow**.

#### 3. Workflow “Pubblica Social” (`pubblica_social.json`)
1. **Nodo Webhook**  
   - Metodo: POST  
   - Path: `/webhook-pubblica`
   - Parametri: `reel_id`, `video_path`
2. **Nodo Function (simula o chiama API social)**  
   - Se integri API reali, slave:  
     1. Nodo “HTTP Request” per caricare media (Instagram Graph / TikTok).  
     2. Nodo “HTTP Request” per pubblicare.  
     3. Restituisci `social_post_id`.  
   - Se simuli:
     ```js
     return {
       json: {
         reel_id: $json.reel_id,
         social_post_id: "sim_" + $json.reel_id
       }
     };
     ```
3. **Nodo HTTP Request (Callback Laravel)**  
   - URL: `http://host.docker.internal:8000/api/reels/{{ $json.reel_id }}/status`  
   - Metodo: POST  
   - Body:
     ```json
     {
       "status": "published",
       "social_post_id": "{{ $json.social_post_id }}"
     }
     ```
4. **Salva e attiva il workflow**.

---

## Esecuzione e Testing

1. **Avvia Laravel**  
   ```bash
   php artisan serve --port=8000
   ```
2. **Avvia n8n**  
   ```bash
   # Se in Docker, già in esecuzione
   docker logs -f n8n
   # Se con npm
   n8n start
   ```
3. **Crea un nuovo Reel**  
   ```bash
   curl -X POST http://localhost:8000/api/reels      -H "Content-Type: application/json"      -d '{"argomento": "Come allacciarsi le scarpe"}'
   ```
   - Riceverai una risposta con l’`id` del nuovo reel, ad es. `{ "id": 1 }`.
4. **Monitora lo stato**  
   - Ogni volta che n8n richiama Laravel per aggiornare lo stato, puoi controllare via API:
     ```bash
     curl http://localhost:8000/api/reels/1
     ```
     Dovresti vedere campi come `status: "script_generated"`, poi `status: "video_ready"`, infine `status: "published"`.
5. **Scarica il video**  
   - Quando `status = "video_ready"`, troverai il file in `storage/app/public/reels/reel_1.mp4`.  
   - Se non lo vedi, controlla le policy di link simbolici:
     ```bash
     php artisan storage:link
     ```
6. **Verifica “pubblicazione”**  
   - Se stai simulando, il campo `social_post_id` avrà un prefisso “sim_”.  
   - Se integri le API reali, verifica dalla piattaforma (Instagram, TikTok) se il reel è apparso.

---

## Debug e Monitoraggio
- **Log Laravel**: in `storage/logs/laravel.log` troverai eventuali errori PHP o comandi FFmpeg falliti.
- **Log n8n**: se in Docker, `docker logs n8n`; se a terminale, controlla l’output live dei nodi.
- **Database**: ispeziona la tabella `reels` per capire se ci sono record con `status = 'error'` e leggere `error_message`.
- **Endpoint manuali**:  
  - Forza la generazione video:
    ```bash
    curl -X POST http://localhost:8000/api/reels/1/create-video       -H "Content-Type: application/json"       -d '{"script": "Testo di prova"}'
    ```
  - Forza pubblicazione:
    ```bash
    curl -X POST http://localhost:8000/api/reels/1/status       -H "Content-Type: application/json"       -d '{"status": "published", "social_post_id": "test123"}'
    ```
- **Coda Laravel** (opzionale): se preferisci usare le Code di Laravel per gestire la generazione video in background (anziché chiamare direttamente l’endpoint da n8n), puoi configurare:
  - Redis / Database come driver queue.
  - Job `GenerateReelVideo` che esegue il comando FFmpeg asynchronously.
  - Modificare il workflow “Crea Video” per invocare un endpoint che spinge un Job in coda.

---

## Possibili Estensioni Future

1. **Video più complessi**  
   - TTS (Text-to-Speech) con Azure / Amazon Polly / Google Cloud per generare audio e mixarlo nel video.
   - Template video pre-registrati (clip di stock, animazioni).  
   - Montaggio dinamico: unire più clip, transizioni, effetti.

2. **Integrazione reale Social**  
   - Richiedere accesso Instagram Graph API Business:
     - Creare un’app Facebook Developers.
     - Ottenere permessi `instagram_content_publish`, `pages_read_engagement`.
     - Automatizzare token refresh e caricamento.
   - Valutare TikTok API (Richiede approvazione garantita per caricare video).

3. **Interfaccia Web / Dashboard**  
   - Costruire un frontend con Livewire / Vue per:
     - Creare nuovi reel via form.
     - Vedere anteprima del video (player).
     - Programmare pubblicazione (campo di data/ora).
     - Vedere statistiche di performance (like, commenti).

4. **Schedulazione e Pianificazione**  
   - Usare `cron` di Laravel (Scheduler) per far partire il workflow a orari prefissati:
     - Creare un “piano editoriale” di reel che si autopubblicano ogni giorno.
   - Pianificare flussi n8n (n8n Supporta scheduling interno).

5. **Multilingua / Temi**  
   - Permettere all’utente di scegliere lingua del tutorial (italiano, inglese).
   - Diversi temi di “tutorial ovvi”: “cucina”, “meccanica di base”, “hacking quotidiano”.

---

## Riferimenti Utili

- **Laravel Official Docs**:  
  https://laravel.com/docs
- **n8n Documentation**:  
  https://docs.n8n.io
- **OpenAI API**:  
  https://platform.openai.com/docs/api-reference
- **FFmpeg Drawtext Filter**:  
  https://ffmpeg.org/ffmpeg-filters.html#drawtext
- **Instagram Graph API (Reels)**:  
  https://developers.facebook.com/docs/instagram-api/guides/reels  
- **TikTok for Developers**:  
  https://developers.tiktok.com

---

### Note Finali
- Questo POC è pensato per funzionare **completamente in locale**, con workflow n8n e server Laravel in esecuzione sulla tua macchina.  
- **Assicurati** di eseguire i comandi `php artisan storage:link` per rendere accessibili i video nella cartella `public/storage/reels`.  
- Se incappi in errori di permessi o path, verifica i permessi della directory `storage/` e che FFmpeg sia correttamente installato e nel PATH di sistema.  
- Durante lo sviluppo, mantieni attivi i log (`APP_DEBUG=true`) per avere feedback dettagliati.

> **Consiglio da tutor:** comincia con la sola parte “Genera Script” (Laravel + n8n + OpenAI). Verifica che tutto fili liscio, poi aggiungi gradualmente la “Creazione Video” e infine la “Pubblicazione Social”. In questo modo riduci la complessità e puoi isolare eventuali errori per ciascuna fase.

Buon lavoro e buon divertimento nel realizzare il tuo POC di reel “tutorial ovvi”! Se hai domande in corso d’opera o incontri problemi specifici (ad esempio errori FFmpeg, problemi di permessi, configurazione n8n), fammi sapere e approfondiamo il debug insieme.
