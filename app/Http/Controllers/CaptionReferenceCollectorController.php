<?php

namespace App\Http\Controllers;

use App\Models\CaptionReference;
use App\Services\CaptionReferenceCollectorService;
use Illuminate\Http\Request;

class CaptionReferenceCollectorController extends Controller
{
    private CaptionReferenceCollectorService $collectorService;

    public function __construct(CaptionReferenceCollectorService $collectorService)
    {
        $this->collectorService = $collectorService;
    }

    public function index()
    {
        $recentReferences = CaptionReference::latest()->take(10)->get();
        return view('caption_references.collector', compact('recentReferences'));
    }

    public function collectUrls(Request $request)
    {
        $request->validate([
            'urls' => 'required|string',
            'category' => 'nullable|string',
            'language' => 'nullable|string',
        ]);

        $urls = array_filter(array_map('trim', explode("\n", $request->input('urls'))));
        
        if (empty($urls)) {
            return back()->with('error', 'Please provide valid URLs.');
        }

        $options = [
            'category' => $request->input('category', 'clip'),
            'language' => $request->input('language', 'id'),
        ];

        $result = $this->collectorService->collectFromUrls($urls, $options);

        return back()->with('success', "Collection finished. Imported: {$result['imported']}, Skipped: {$result['skipped']}, Failed: " . count($result['failed']));
    }

    public function collectYouTubeSearch(Request $request)
    {
        $request->validate([
            'query' => 'required|string',
            'max' => 'nullable|integer|min:1|max:50',
            'category' => 'nullable|string',
            'language' => 'nullable|string',
        ]);

        $options = [
            'category' => $request->input('category', 'clip'),
            'language' => $request->input('language', 'id'),
            'max' => $request->input('max', 25),
        ];

        $result = $this->collectorService->collectFromYouTubeSearch($request->input('query'), $options);

        if (!empty($result['failed']) && $result['failed'][0]['url'] === 'search') {
            return back()->with('error', 'Search failed: ' . $result['failed'][0]['error']);
        }

        return back()->with('success', "Search collection finished. Imported: {$result['imported']}, Skipped: {$result['skipped']}");
    }
}
