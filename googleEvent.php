<?php

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Google_Service_Calendar;
use Google_Service_Calendar_Event;
use Google_Service_Calendar_Events;

class GoogleEvent
{
    private $service;
    private $calendarId;

    public function __construct(string $credentialsJson, string $calendarId)
    {
        $client = new Google_Client;

        $client->setScopes([
            Google_Service_Calendar::CALENDAR,
        ]);

        $client->setAuthConfig($credentialsJson);
        $this->calendarId = $calendarId;
        $this->service = new Google_Service_Calendar($client);
    }

    public function getEvents(CarbonInterface $startDateTime = null, CarbonInterface $endDateTime = null, array $queryParameters = []): Google_Service_Calendar_Events
    {
        $parameters = [
            'singleEvents' => true,
            // 'orderBy' => 'startTime',
            'maxResults' => 99999,

        ];

        if (is_null($startDateTime)) {
            // $startDateTime = Carbon::now()->startOfDay();
            $startDateTime = Carbon::parse('2009-01-01');
        }

        $parameters['timeMin'] = $startDateTime->format(DateTime::RFC3339);

        if (is_null($endDateTime)) {
            $endDateTime = Carbon::now()->addYear()->endOfDay();
        }
        $parameters['timeMax'] = $endDateTime->format(DateTime::RFC3339);

        $parameters = array_merge($parameters, $queryParameters);

        return $this->service
            ->events
            ->listEvents($this->calendarId, $parameters);
    }

    public function getEventsSync(?string $lastSyncToken)
    {
        if (is_null($lastSyncToken)) {
            return $this->getEvents();
        }

        $parameters = [
            'singleEvents' => true,
            'syncToken' => $lastSyncToken,
        ];

        return $this->service
            ->events
            ->listEvents($this->calendarId, $parameters);
    }

    public function delete(string $eventId)
    {
        return $this->service->events->delete($this->calendarId, $eventId);
    }

    public function insert($name, $startDate, $endDate, $startTime = null, $endTime = null)
    {
        if ($startTime == null) {
            $start = [
                'date' => Carbon::parse($startDate)->format('Y-m-d'),
            ];
            $end = [
                'date' => Carbon::parse($endDate)->format('Y-m-d'),
            ];
        } else {
            $start = [
                'date' => Carbon::parse($startDate.' '.$startTime)->format(DateTime::RFC3339),
            ];
            $end = [
                'date' => Carbon::parse($endDate.' '.$endTime)->format(DateTime::RFC3339),
            ];
        }

        $event = new Google_Service_Calendar_Event([
            'summary' => $name,
            'start' => $start,
            'end' => $end,
        ]);
        return $this->service->events->insert($this->calendarId, $event);
    }

    public function update($eventId, $name, $startDate, $endDate, $startTime = null, $endTime = null)
    {
        if ($startTime == null) {
            $start = [
                'date' => Carbon::parse($startDate)->format('Y-m-d'),
            ];
            $end = [
                'date' => Carbon::parse($endDate)->format('Y-m-d'),
            ];
        } else {
            $start = [
                'date' => Carbon::parse($startDate.' '.$startTime)->format(DateTime::RFC3339),
            ];
            $end = [
                'date' => Carbon::parse($endDate.' '.$endTime)->format(DateTime::RFC3339),
            ];
        }

        $event = new Google_Service_Calendar_Event([
            'summary' => $name,
            'start' => $start,
            'end' => $end,
        ]);
        return $this->service->events->update($this->calendarId, $eventId, $event);
    }

    public function get($eventId)
    {
        return $this->service->events->get($this->calendarId, $eventId);
    }

    public static function sync($credentialsJson, $calendarId)
    {
        $syncToken = LogCron::where('type', 'GoogleEvent')
                                    ->where('code', $calendarId)
                                    ->orderBy('isrt_dt', 'desc')
                                    ->limit(1)
                                    ->value('data');

        $service = new GoogleEvent($credentialsJson, $calendarId);
        $events = $service->getEventsSync($syncToken);

        LogCron::insert([
            'type' => 'GoogleEvent',
            'code' => $calendarId,
            'data' => $events->nextSyncToken,
            'isrt_dt' => date('Y-m-d H:i:s.u'),
        ], false, false);

        foreach ($events->items as $event) {
            switch ($event->status) {
                case 'cancelled':
                    $ref_id = DB::table('schedules')->where('event_id', $event->id)->limit(1)->value('id');
                    DB::table('schedule_details')->where('ref_id', $ref_id)->delete();
                    DB::table('schedules')->where('id', $ref_id)->delete();
                    break;
                case 'confirmed':
                default:
                    $ref_id = DB::table('schedules')->where('event_id', $event->id)->limit(1)->value('id');
                    if (!is_null($ref_id)) { // 수정되었다 판단하고 내용을 지우고 다시 인서트
                        DB::table('schedule_details')->where('ref_id', $ref_id)->delete();
                        DB::table('schedules')->where('id', $ref_id)->delete();
                    }

                    $date = Carbon::parse($event->start->date ?? substr($event->start->dateTime, 0, 10));
                    $dateDiff = ceil(Carbon::parse($event->start->date ?? $event->start->dateTime)->floatDiffInDays(Carbon::parse($event->end->date ?? $event->end->dateTime)));

                    if ($dateDiff == 0) {
                        $dateDiff = 1;
                    }

                    $ref_id = DB::table('schedules')->insertGetId([
                        'name' => $event->summary ?? '제목없음',
                        'desc' => null,
                        'start_date' => $event->start->date,
                        'end_date' => $event->end->date,
                        'start_datetime' => $event->start->dateTime!=null?Carbon::parse($event->start->dateTime)->format('Y-m-d H:i:s'):null,
                        'end_datetime' => $event->end->dateTime!=null?Carbon::parse($event->end->dateTime)->format('Y-m-d H:i:s'):null,
                        'event_id' => $event->id,
                    ], false, false);

                    for ($i=0; $i < $dateDiff; $i++) {
                        $tempDT = $date;
                        $dateT = Carbon::parse($tempDT)->addDays($i)->format('Y-m-d');

                        DB::table('schedule_details')->insert([
                            'ref_id' => $ref_id,
                            'date' => $dateT,
                        ], false, false);
                    }

                    break;
            }
        }
    }
}
