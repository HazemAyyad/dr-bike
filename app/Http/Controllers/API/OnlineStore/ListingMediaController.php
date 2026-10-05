<?php

namespace App\Http\Controllers\API\OnlineStore;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnlineStore\ManageListingRequest;
use App\Http\Requests\OnlineStore\ReplaceListingMediaRequest;
use App\Http\Resources\OnlineStore\StorefrontListingResource;
use App\Models\OnlineStore\OnlineStoreListing;
use App\Services\OnlineStore\ListingLifecycleService;
use App\Services\OnlineStore\MediaPresentationService;

class ListingMediaController extends Controller
{
    public function show(ManageListingRequest $request, OnlineStoreListing $listing, MediaPresentationService $media): array
    {
        return ['data' => ['items' => $media->adminPresentation($listing->load('product')), 'listing' => new StorefrontListingResource($listing)]];
    }

    public function initialize(ManageListingRequest $request, OnlineStoreListing $listing, MediaPresentationService $media, ListingLifecycleService $lifecycle): StorefrontListingResource
    {
        $listing = $media->initialize($listing, $request->user());

        return new StorefrontListingResource($lifecycle->refresh($listing, $request->user()));
    }

    public function replace(ReplaceListingMediaRequest $request, OnlineStoreListing $listing, MediaPresentationService $media, ListingLifecycleService $lifecycle): StorefrontListingResource
    {
        $listing = $media->replace($listing, $request->validated('items'), $request->user());

        return new StorefrontListingResource($lifecycle->refresh($listing, $request->user()));
    }
}
