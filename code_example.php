public function show(InfluxDBService $influx, string $id, Request $req)
    {
        $validated = $req->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
        ]);

        $group = MetricGroup::with(['recipes'])->findOrFail($id);

        $data = [];

        $startDateTime = new \DateTime($validated['start']);
        $endDateTime = new \DateTime($validated['end']);

        $start_date = $startDateTime->format('Y-m-d');
        $start_time = $startDateTime->format('H:i:s');

        $end_date = $endDateTime->format('Y-m-d');
        $end_time = $endDateTime->format('H:i:s');

        // Dinamički ekstraktuj bucket naziv iz $group->name
        // Npr: ako je name "cip_1_sig", bucket će biti "meggle_cip_1_sig"
        // ako je name "cip_2_sig", bucket će biti "meggle_cip_2_sig"
        $bucket_suffix = $group->name;
        $bucket = "meggle_$bucket_suffix";

        $flux = <<<FLUX
import "date"
import "timezone"
import "strings"

option location = timezone.location(name: "Europe/Belgrade")

BUCKET      = "$bucket"
MEASUREMENT = "cip_pranje"

start_date = "$start_date"
start_time = "$start_time"
end_date   = "$end_date"
end_time   = "$end_time"

sh = int(v: strings.substring(v: start_time, start: 0, end: 2))
sm = int(v: strings.substring(v: start_time, start: 3, end: 5))
ss = int(v: strings.substring(v: start_time, start: 6, end: 8))
eh = int(v: strings.substring(v: end_time,   start: 0, end: 2))
em = int(v: strings.substring(v: end_time,   start: 3, end: 5))
es = int(v: strings.substring(v: end_time,   start: 6, end: 8))

start_midnight = date.truncate(t: time(v: start_date + "T12:00:00Z"), unit: 1d, location: location)
end_midnight   = date.truncate(t: time(v: end_date   + "T12:00:00Z"), unit: 1d, location: location)

_s1     = date.add(d: duration(v: string(v: sh) + "h"), to: start_midnight)
_s2     = date.add(d: duration(v: string(v: sm) + "m"), to: _s1)
start_t = date.add(d: duration(v: string(v: ss) + "s"), to: _s2)
_e1     = date.add(d: duration(v: string(v: eh) + "h"), to: end_midnight)
_e2     = date.add(d: duration(v: string(v: em) + "m"), to: _e1)
stop_t  = date.add(d: duration(v: string(v: es) + "s"), to: _e2)

from(bucket: BUCKET)
  |> range(start: start_t, stop: stop_t)
  |> filter(fn: (r) => r["_measurement"] == MEASUREMENT)
  |> filter(fn: (r) => r["_field"] == "program")
  |> map(fn: (r) => ({
      _time:      r._time,
      _value:     r._value,
      program:    "Program " + string(v: int(v: r._value)),
      started:    string(v: date.hour(t: r._time, location: location)) + ":" +
                  (if date.minute(t: r._time, location: location) < 10 then "0" else "") +
                  string(v: date.minute(t: r._time, location: location)),
      local_date: date.truncate(t: date.add(d: duration(v: "24h"), to: r._time), unit: 1d, location: location)
  }))
  |> keep(columns: ["_time", "_value", "program", "started", "local_date"])
FLUX;

        try {
            $tables = $influx->query($flux);

            foreach ($tables as $table) {
                foreach ($table->records as $record) {
                    $data[] = [
                        'time' => $record->values['started'],
                        'program' => $record->values['program'],
                        'program_value' => $record->getValue(),
                        'timestamp' => $record->getTime(),
                    ];
                }
            }
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'errors.influx']);
        }

        return Inertia::render('admin/recipe-history/show', [
            'group' => $group,
            'data' => $data,
            'start' => $validated['start'],
            'end' => $validated['end'],
        ]);
    }
