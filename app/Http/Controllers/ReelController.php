<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Reel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ReelController extends Controller
{
    // 1. Crea un nuovo reel e invia richiesta a n8n
    public function store(Request $request)
    {
        $request->validate([
            'argomento' => 'required|string|max:255',
        ]);

        // Crea subito il record “pending”
        $reel = Reel::create([
            'argomento'     => $request->argomento,
            'status'        => 'pending',
            'script'        => null,
            'video_path'    => null,
            'social_post_id'=> null,
        ]);

        // URL di n8n: verifica che sia corretto (porta, path, host)
        $n8nUrl = env('N8N_WEBHOOK_URL');

        Log::info("Chiamata a n8n per reel {$reel->id} su URL: {$n8nUrl}");

        try {
            $response = Http::post($n8nUrl, [
                'reel_id'   => $reel->id,
                'argomento' => $reel->argomento,
            ]);
        } catch (\Exception $e) {
            // Se c’è un errore di connessione (timeout, DNS, ecc.)
            Log::error("Errore di connessione a n8n: " . $e->getMessage());
            $reel->update([
                'status'        => 'error',
                'error_message' => "Connessione a n8n fallita: " . $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Impossibile avviare workflow: connessione a n8n fallita.',
            ], 500);
        }

        // Se il server n8n risponde con un errore HTTP (4xx o 5xx), non è successful()
        if (! $response->successful()) {
            // Logga il codice e il corpo di risposta
            Log::error("n8n REST returned HTTP {$response->status()}: {$response->body()}");
            $reel->update([
                'status'        => 'error',
                'error_message' => "n8n response error: HTTP {$response->status()}",
            ]);

            return response()->json([
                'error' => 'Impossibile avviare workflow (n8n ha risposto con errore).',
            ], 500);
        }

        // Se siamo arrivati qui, la richiesta verso n8n è andata a buon fine
        Log::info("Workflow n8n avviato correttamente per reel {$reel->id}.");

        return response()->json([
            'id'      => $reel->id,
            'message' => 'Richiesta ricevuta, script in generazione',
        ], 201);
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

            case 'processing':
                $reel->update([
                    'status' => 'processing',
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
    /**
     * Genera un video tramite OpenAI Sora e salva in storage/app/public/reels
     *
     * Route: POST /api/reels/{id}/create-video-sora
     * Body JSON: { "script": "<testo>" }
     */
    public function generateVideoWithSora(Request $request, $id)
    {
        // 1) Recupera il reel dal DB
        $reel = Reel::findOrFail($id);

        // 2) Validazione minima
        $request->validate([
            'script' => 'required|string',
        ]);
        $script = $request->input('script');

        // 3) Definisci il payload per Sora
        //    Qui assumiamo che il modello si chiami "sora" o "sora-1". Se serve un altro nome,
        //    verifica con il GET /v1/models.
        $payload = [
            'model'    => 'sora',      // oppure 'sora-1' se disponibile
            'prompt'   => $script,
            'size'     => '720x1280',  // vertical per Reel—puoi scegliere 1080x1920
            'duration' => 10,          // durata in secondi (il POC genera 10s)
            'n'        => 1            // numero di varianti (1 video)
        ];

        // 4) Chiamata a OpenAI API
        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->timeout(180) // video può richiedere tempo
            ->post('https://api.openai.com/v1/video/generations', $payload);

        // 5) Se fallisce la richiesta, aggiorna lo stato a 'error'
        if ($response->failed()) {
            // Leggiamo eventuale messaggio di errore ritornato
            $errorJson = $response->json();
            $errorMsg  = $errorJson['error']['message'] ?? 'Errore generazione video';
            $reel->update([
                'status'        => 'error',
                'error_message' => "Sora API: {$errorMsg}"
            ]);
            return response()->json([
                'error'   => 'Errore generazione video',
                'details' => $errorJson
            ], 500);
        }

        // 6) Estrai l’URL del video generato (presumibilmente su S3 o CDN temporanea)
        //    Formato atteso: { data: [ { url: "https://..." } ] }
        $dataArray = $response->json('data');
        if (!isset($dataArray[0]['url'])) {
            $reel->update([
                'status'        => 'error',
                'error_message' => 'Risposta Sora non contiene URL valido'
            ]);
            return response()->json([
                'error' => 'No URL returned from Sora'
            ], 500);
        }
        $videoUrl = $dataArray[0]['url'];

        // 7) Scarica il video da quell’URL e salvalo in storage/app/public/reels/reel_{id}.mp4
        try {
            $videoContents = Http::timeout(180)->get($videoUrl)->body();

            // Definiamo il path completo in disco (storage)
            $filenameInStorage = "public/reels/reel_{$id}.mp4";
            Storage::put($filenameInStorage, $videoContents);

            // 8) Aggiorna il record: status e video_path (permette di servire da URL pubblica)
            $reel->update([
                'status'     => 'video_ready',
                // Il campo video_path serve a costruire "~/{storage/reels/…}" in GET /reels/{id}
                'video_path' => "storage/reels/reel_{$id}.mp4",
            ]);
        } catch (\Exception $e) {
            // Se il download fallisce
            $reel->update([
                'status'        => 'error',
                'error_message' => "Errore download/salvataggio video: " . $e->getMessage(),
            ]);
            return response()->json([
                'error' => 'Errore download o salvataggio',
                'details' => $e->getMessage()
            ], 500);
        }

        // 9) Ritorna JSON di successo
        return response()->json([
            'message'    => 'Video generato con successo',
            'video_path' => $reel->video_path,
        ]);
    }
    /**
     * 1) Avvia la generazione video con Luma Dream Machine
     * Route: POST /api/reels/{id}/create-video-luma
     * Body JSON: { "script": "<string>" }
     */
    public function generateVideoWithLuma(Request $request, $id)
    {
        // 1.1) Recupera il record del reel
        $reel = Reel::findOrFail($id);

        // 1.2) Validazione minima: serve almeno lo script
        $request->validate([
            'script' => 'required|string',
        ]);
        $script = $request->input('script');

        // 1.3) Costruisci il payload per Luma
        //     - “ray-2” è il modello text-to-video più comune
        //     - resolution: “720p” → video 1280×720 vertical (9:16) o orizzontale (16:9), a seconda delle esigenze
        //     - duration: “5s”, “10s”, etc.
        $payload = [
            'prompt'     => $script,
            'model'      => 'ray-2',
            'resolution' => '720p',
            'duration'   => '5s',    // qui usiamo 10 secondi come esempio per un reel breve
            // Puoi aggiungere altri parametri (keyframes, loop, aspect_ratio…) se vuoi
            'loop'       => true,
            // 'aspect_ratio' => '9:16',
        ];

        // 1.4) Invia la richiesta a Luma
        $response = Http::withToken(env('LUMA_API_KEY'))
                        ->timeout(120)
                        ->post('https://api.lumalabs.ai/dream-machine/v1/generations', $payload);

        if ($response->failed()) {
            $errorBody = $response->json();
            $msg = $errorBody['error'] ?? 'Errore invocazione Luma API';
            $reel->update([
                'status'        => 'error',
                'error_message' => "Luma API: {$msg}",
            ]);
            return response()->json([
                'error'   => 'Impossibile avviare generazione video Luma',
                'details' => $errorBody,
            ], 500);
        }

        // 1.5) Estrai l’ID del job (campo "id" oppure "generationId" a seconda della versione)
        //     Es. Luma restituisce: { "id": "job_abcdef123456", "status": "pending" }
        $json  = $response->json();
        $jobId = $json['id'] ?? null;
        if (! $jobId) {
            $reel->update([
                'status'        => 'error',
                'error_message' => 'Nessun jobId restituito da Luma',
            ]);
            return response()->json([
                'error' => 'Missing jobId da Luma',
            ], 500);
        }

        // 1.6) Aggiorna lo stato del reel in DB
        $reel->update([
            'status'         => 'processing',    // “processing” finché Luma non invia la callback
            'social_post_id' => $jobId,          // usiamo social_post_id per memorizzare temporaneamente il jobId
            'error_message'  => null,
        ]);

        // 1.7) Rispondi subito con successo e jobId
        return response()->json([
            'message' => 'Job Luma avviato con successo',
            'jobId'   => $jobId,
        ]);
    }

    /**
     * 2) Callback ricevuto da Luma quando il video è pronto
     * Route: POST /webhook/luma-callback
     * Body JSON di esempio:
     * {
     *   "id": "job_abcdef123456",
     *   "status": "succeeded",
     *   "result": {
     *     "url": "https://cdn.lumalabs.ai/videos/abc12345.mp4"
     *   }
     * }
     */
    public function handleLumaCallback(Request $request)
    {
        $payload = $request->all();
        $jobId   = $payload['id'] ?? null;
        $status  = $payload['status'] ?? null;
        $videoUrl= $payload['result']['url'] ?? null;

        if (! $jobId) {
            return response()->json(['error' => 'Missing jobId nel callback'], 400);
        }

        // 2.1) Trova il reel corrispondente in DB (social_post_id = jobId)
        $reel = Reel::where('social_post_id', $jobId)->first();
        if (! $reel) {
            return response()->json(['error' => "Reel non trovato per jobId {$jobId}"], 404);
        }

        // 2.2) Se Luma indica "failed" o non c’è URL, segna errore
        if ($status !== 'succeeded' || ! $videoUrl) {
            $reel->update([
                'status'        => 'error',
                'error_message' => $payload['error'] ?? 'Generazione Luma fallita',
            ]);
            return response()->json(['message' => 'Generazione fallita'], 200);
        }

        // 2.3) Scarica il video dall’URL e salvalo in storage
        try {
            $videoContents = Http::timeout(120)->get($videoUrl)->body();
            $storagePath   = "public/reels/reel_{$reel->id}.mp4";
            Storage::put($storagePath, $videoContents);
            $reel->update([
                'status'     => 'video_ready',
                'video_path' => "storage/reels/reel_{$reel->id}.mp4",
            ]);
        } catch (\Exception $e) {
            $reel->update([
                'status'        => 'error',
                'error_message' => "Errore download video: " . $e->getMessage(),
            ]);
            return response()->json(['error' => 'Download o salvataggio fallito'], 200);
        }

        return response()->json(['message' => 'Callback Luma gestito con successo'], 200);
    }
    /**
     * 3) Controlla lo stato del job Luma e, se completato, scarica il video.
     * Route: GET /api/reels/{id}/check-video-luma
     */
    public function checkLumaStatus(Request $request, $id)
    {
        $reel = Reel::findOrFail($id);

        // Deve esistere un jobId salvato in social_post_id
        $jobId = $reel->social_post_id;
        if (! $jobId) {
            return response()->json([
                'error' => 'Nessun jobId associato a questo reel'
            ], 400);
        }

        // 1) Chiediamo a Luma lo stato del job
        $response = Http::withToken(env('LUMA_API_KEY'))
                        ->timeout(30)
                        ->get("https://api.lumalabs.ai/dream-machine/v1/generations/{$jobId}");

        if ($response->failed()) {
            return response()->json([
                'error'   => 'Impossibile contattare Luma',
                'details' => $response->json()
            ], 500);
        }

        $json = $response->json();
        $state = $json['state'] ?? null;

        // 2) Se non è completato, rispondo indicando che devo riprovare
        if ($state !== 'completed') {
            return response()->json([
                'status'   => $state,
                'message'  => 'Video non ancora pronto',
                'updated'  => $reel->updated_at,
            ], 200);
        }

        // 3) Se è completed, verifico che abbia gli assets
        $videoUrl = $json['assets']['video'] ?? null;
        if (! $videoUrl) {
            return response()->json([
                'error' => 'Job completato senza URL video'
            ], 500);
        }

        // 4) Scarico il video (una sola volta)
        //    Verifico prima se in DB ho già settato video_path
        if (! $reel->video_path) {
            try {
                $videoContents = Http::timeout(60)->get($videoUrl)->body();
                $storagePath   = "public/reels/reel_{$reel->id}.mp4";
                Log::info("Sto per salvare il video da Luma: {$videoUrl}");
                Storage::put($storagePath, $videoContents);
                Log::info("File salvato in: {$storagePath}");

                $reel->update([
                    'status'     => 'video_ready',
                    'video_path' => "storage/reels/reel_{$reel->id}.mp4",
                    'updated_at' => Carbon::now(),
                ]);
            } catch (\Exception $e) {
                $reel->update([
                    'status'        => 'error',
                    'error_message' => "Errore download: " . $e->getMessage(),
                ]);
                return response()->json([
                    'error'   => 'Errore download/salvataggio video',
                    'details' => $e->getMessage()
                ], 500);
            }

            return response()->json([
                'message'    => 'Video scaricato e salvato con successo',
                'video_path' => $reel->video_path
            ], 200);
        }

        // 5) Se arriva qui, significa che avevamo già salvato video_path
        return response()->json([
            'message'    => 'Video già salvato',
            'video_path' => $reel->video_path
        ], 200);
    }
}
