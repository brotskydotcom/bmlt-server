<?php

namespace App\Http\Resources\Query;

use Illuminate\Http\Resources\Json\JsonResource;
use Carbon\Carbon;

class TsmlMeetingResource extends JsonResource
{
    protected mixed $formatsById;

    public function __construct($resource, $formatsById = [])
    {
        parent::__construct($resource);
        $this->formatsById = $formatsById;
    }

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request): array
    {
        $formatsById = $this->formatsById;
        // Mapping formats to TSML types.
        // Servers may use arbitrary `key_string` values for formats, which are not guaranteed to be unique.
        // To help with consistency, we try to map format IDs to their `worldid_mixed` codes, which are
        // standardized. If we find a standard code, we use it, otherwise we use the `key_string` value.
        //
        // All of the `worldid_mixed` codes have matching codes pre-defined in TSML for the Al-Anon program.
        // (Well, not quite all, there is no code for sign language, but a bug has been filed.)
        // The webmaster may have to define codes for any `key_string` values that get passed through.
        //
        // Note: We always pass the key_string for any `LANG` codes, because they are languages. The
        // codes `HYBRID` and `VM` are pseudo-codes required by BMLT but have no TSML equivalents, because
        // TSML makes this decision based on whether there's a physical address and a valid online link,
        // so we ignore these two codes.
        //
        // This code has to be updated whenever the `worldid_mixed` values change. The code here is based
        // on this dump of the Al-Anon WSO codes as of 2025-10-28:
        /*
        ```
            +---------------+------------+--------------------+
            | worldid_mixed | key_string | name_string        |
            +---------------+------------+--------------------+
            | AC            | AC         | Adult Children     |
            | BEG           | B          | Beginners          |
            | CC            | CC         | Child Care         |
            | CLOSED        | C          | Closed             |
            | FF            | FF         | Fragrance Free     |
            | HYBR          | HY         | Hybrid Meeting     |
            | LANG          | ENG        | English            |
            | LANG          | ESP        | Español            |
            | LANG          | FRA        | Français           |
            | LGBT          | LGBT       | LGBTQIA+           |
            | M             | M          | Men                |
            | OPEN          | O          | Open               |
            | P             | P          | Parents            |
            | POC           | POC        | People of Color    |
            | SL            | SL         | Sign Language      |
            | SMOK          | SMOK       | Smoking Permitted  |
            | TC            | TC         | Temporarily Closed |
            | VM            | VM         | Virtual Meeting    |
            | W             | W          | Women              |
            | WCHR          | WC         | Handicap Access    |
            | Y             | Y          | Alateen            |
            | YA            | YA         | Young Adults       |
            +---------------+------------+--------------------+
         ```
         */
        $worldIdToTsmlTypes = [
            'AC' => 'AC',
            'BEG' => 'BE',
            'CC' => 'BA',
            'CLOSED' => 'C',
            'FF' => 'FF',
            'HYBR' => '*ignore*',
            'LANG' => '*use-key*',
            'LGBT' => 'LGBTQIA',
            'M' => 'M',
            'OPEN' => 'O',
            'P' => 'POA',
            'POC' => 'POC',
            'SL' => 'SL',
            'SMOK' => 'SM',
            'VM' => '*ignore*',
            'W' => 'W',
            'WCHR' => 'X',
            'Y' => 'Y',
            'YA' => 'YA',
        ];

        $data = $this->data->mapWithKeys(fn ($d) => [$d->key => $d->data_string])->toArray();
        $longdata = $this->longdata->mapWithKeys(fn ($d) => [$d->key => $d->data_blob])->toArray();
        $allData = array_merge($data, $longdata);

        $meetingFormats = explode(',', $this->formats ?? '');
        $virtualOnly = in_array('VM', $meetingFormats) && !in_array('HY', $meetingFormats);

        $getTsmlCode = function ($key) use ($formatsById, $worldIdToTsmlTypes) {
            if (isset($formatsById[$key])) {
                $worldId = $formatsById[$key]->worldid_mixed;
                if (empty($worldId)) {
                    return $formatsById[$key]->key_string;
                }
                $tsmlCode = $worldIdToTsmlTypes[$worldId];
                if (empty($tsmlCode)) {
                    return $formatsById[$key]->key_string;
                } else if ($tsmlCode == '*ignore*') {
                    return null;
                } else if ($tsmlCode == '*use-key*') {
                    return $formatsById[$key]->key_string;
                } else {
                    return $tsmlCode;
                }
            } else {
                return null;
            }
        };

        $computeNotes = function ($comments, $worldid_mixed) {
            $prefix = empty($comments) ? '' : $comments;
            $suffix = empty($worldid_mixed) ? '' : "WSO #" . $worldid_mixed;
            return empty($prefix) ? $suffix : $prefix . "\n" . $suffix;
        };

        return [
            'day' => isset($this->weekday_tinyint) ? intval($this->weekday_tinyint)  : null,
            'time' => $this->start_time ? substr($this->start_time, 0, 5) : null,
            'end_time' => (!empty($this->start_time) && !empty($this->duration_time)) ? TsmlMeetingResource::addTimes($this->start_time, $this->duration_time) : null,
            'name' => $allData['meeting_name'] ?? '',
            'location' => $virtualOnly ? 'Online Meeting' : ($allData['location_text'] ?? ''),
            'location_notes' => $virtualOnly ? '' : ($allData['location_info'] ?? ''),
            'formatted_address' => collect(['location_street', 'location_municipality', 'location_province', 'location_postal_code_1', 'location_nation'])->map(fn($key) => $data[$key] ?? '')->filter()->implode(', '),
            'address' => $allData['location_street'] ?? '',
            'city' => $allData['location_municipality'] ?? '',
            'state' => $allData['location_province'] ?? '',
            'postal_code' => $allData['location_postal_code_1'] ?? '',
            'country' => $allData['location_nation'] ?? '',
            'types' => collect($this->formats ? explode(',', $this->formats) : [])
                ->map(fn($id) => $getTsmlCode(intval($id)))
                ->filter()
                ->values()
                ->toArray(),
            'notes' => $computeNotes($allData['comments'] ?? '', $this->worldid_mixed ?? ''),
            'coordinates' =>  $this->venue_type == 2
                ? null
                : (($this->latitude && $this->longitude) ? "{$this->latitude},{$this->longitude}" : null),
            'slug' => \Str::slug($allData['meeting_name']) . '-' . $this->id_bigint,
            'updated' => $this->updated_at ?? null,
            'region' => $this->serviceBody->name_string ?? '',
            'regions' => [$this->serviceBody->name_string ?? ''],
            'conference_url' => $allData['virtual_meeting_link'] ?? null,
            'conference_url_notes' => $allData['virtual_meeting_additional_info'] ?? null,
            'conference_phone' => $allData['phone_meeting_number'] ?? null,
            'conference_phone_notes' => $allData['phone_meeting_additional_info'] ?? null,
        ];
    }

    /**
     * Create a collection of resources with formatsById mapping.
     *
     * Note: We pass in $formatsById separately because the $meeting objects themselves
     * only contain format IDs (key_strings), and not the full format metadata. This metadata,
     * including `worldid_mixed`, is necessary for mapping to TSML types,
     * and is not available directly on the meeting objects.
     *
     * @param  mixed  $resource
     * @param  array  $formatsById
     * @return \Illuminate\Support\Collection
     */
    public static function collection($resource, $formatsById = [])
    {
        return collect($resource)->map(function ($meeting) use ($formatsById) {
            return (new static($meeting, $formatsById))->toArray(request());
        })->values();
    }

    /**
     * Adds a start time and a duration (both as strings in H:i or H:i:s format) and returns the end time in H:i format.
     *
     * @param string $start    The start time (H:i or H:i:s)
     * @param string $duration The duration to add (H:i or H:i:s)
     * @return string|null     The calculated end time in H:i format, or null if input is invalid
     */
    public static function addTimes(string $start, string $duration): ?string
    {
        $startTime = Carbon::createFromFormat(strlen($start) === 5 ? 'H:i' : 'H:i:s', $start);
        $durationTime = Carbon::createFromFormat(strlen($duration) === 5 ? 'H:i' : 'H:i:s', $duration);

        $endTime = $startTime->copy()
            ->addHours((int) $durationTime->format('H'))
            ->addMinutes((int) $durationTime->format('i'))
            ->addSeconds((int) $durationTime->format('s'));

        return $endTime->format('H:i');
    }
}
