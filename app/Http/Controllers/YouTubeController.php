<?php

namespace App\Http\Controllers;

use App\Models\Clip;
use App\Jobs\UploadClipToYouTubeJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class YouTubeController extends Controller
{
    private function getClient(): \Google_Client
    {
        $client = new \Google_Client();
        $client->setClientId(config('services.youtube.client_id'));
        $client->setClientSecret(config('services.youtube.client_secret'));
        $client->setRedirectUri(config('services.youtube.redirect_uri'));
        $client->addScope('https://www.googleapis.com/auth/youtube.upload');
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    private function getAccessToken()
    {
        if (Storage::disk('local')->exists('youtube_token.json')) {
            return json_decode(Storage::disk('local')->get('youtube_token.json'), true);
        }
        return null;
    }

    public function redirect(Request $request)
    {
        $client = $this->getClient();
        
        // Store intended URL to redirect back after OAuth
        if ($request->has('redirect_to')) {
            session(['youtube_oauth_intended_url' => $request->redirect_to]);
        }
        
        $authUrl = $client->createAuthUrl();
        return redirect()->away($authUrl);
    }

    public function callback(Request $request)
    {
        if (!$request->has('code')) {
            return redirect('/')->with('error', 'YouTube OAuth failed: No code provided.');
        }

        $client = $this->getClient();
        $token = $client->fetchAccessTokenWithAuthCode($request->code);

        if (array_key_exists('error', $token)) {
            return redirect('/')->with('error', 'YouTube OAuth failed: ' . $token['error']);
        }

        Storage::disk('local')->put('youtube_token.json', json_encode($token));

        $intendedUrl = session('youtube_oauth_intended_url', '/');
        session()->forget('youtube_oauth_intended_url');

        return redirect($intendedUrl)->with('success', 'YouTube authentication successful!');
    }

    public function upload(Clip $clip, Request $request)
    {
        if ($clip->status !== 'completed' || !$clip->video_path) {
            return back()->with('error', 'Clip must be completed and have a video file before uploading.');
        }

        if ($clip->youtube_upload_status === 'uploaded' && $clip->youtube_video_id) {
            return back()->with('error', 'This clip is already uploaded to YouTube.');
        }

        $token = $this->getAccessToken();
        
        if (!$token) {
            // No token, redirect to OAuth and come back to results page
            return redirect()->route('youtube.oauth.redirect', ['redirect_to' => url()->previous()]);
        }

        $client = $this->getClient();
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            if ($client->getRefreshToken()) {
                $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                Storage::disk('local')->put('youtube_token.json', json_encode($newToken));
            } else {
                return redirect()->route('youtube.oauth.redirect', ['redirect_to' => url()->previous()]);
            }
        }

        // Set status to queued
        $clip->update([
            'youtube_upload_status' => 'queued',
            'youtube_error_message' => null,
        ]);

        // Dispatch Job
        UploadClipToYouTubeJob::dispatch($clip);

        return back()->with('success', 'Clip has been queued for YouTube upload. This process may take a few minutes.');
    }

    public function status(Clip $clip)
    {
        $clip->refresh();

        return response()->json([
            'id' => $clip->id,
            'youtube_upload_status' => $clip->youtube_upload_status,
            'youtube_video_id' => $clip->youtube_video_id,
            'youtube_url' => $clip->youtube_url,
            'youtube_privacy_status' => $clip->youtube_privacy_status,
            'youtube_publish_status' => $clip->youtube_publish_status,
            'youtube_publish_at' => optional($clip->youtube_publish_at)->toIso8601String(),
            'youtube_scheduled_for_local' => $clip->youtube_scheduled_for_local,
            'youtube_error_message' => $clip->youtube_error_message,
        ]);
    }
}
