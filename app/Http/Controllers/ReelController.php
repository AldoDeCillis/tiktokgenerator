<?php

namespace App\Http\Controllers;

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
        $n8nUrl = 'http://localhost:5678/webhook-test/webhook-tutorial';

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
