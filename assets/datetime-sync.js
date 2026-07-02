(function () {
  function parseDateTime(dateValue, timeValue) {
    if (!dateValue || !timeValue) {
      return null;
    }
    const dt = new Date(dateValue + "T" + timeValue);
    return Number.isNaN(dt.getTime()) ? null : dt;
  }

  function pad(n) {
    return String(n).padStart(2, "0");
  }

  function toDateParts(dt) {
    return {
      date: dt.getFullYear() + "-" + pad(dt.getMonth() + 1) + "-" + pad(dt.getDate()),
      time: pad(dt.getHours()) + ":" + pad(dt.getMinutes()),
    };
  }

  function attachDateTimeSync(startDateEl, startTimeEl, endDateEl, endTimeEl) {
    if (!startDateEl || !startTimeEl || !endDateEl || !endTimeEl) {
      return;
    }

    let intervalMs = 60 * 60 * 1000;

    const currentStart = parseDateTime(startDateEl.value, startTimeEl.value);
    const currentEnd = parseDateTime(endDateEl.value, endTimeEl.value);
    if (currentStart && currentEnd && currentEnd > currentStart) {
      intervalMs = currentEnd.getTime() - currentStart.getTime();
    }

    function syncEndFromStart() {
      const start = parseDateTime(startDateEl.value, startTimeEl.value);
      if (!start) {
        return;
      }

      const nextEnd = new Date(start.getTime() + intervalMs);
      const parts = toDateParts(nextEnd);
      endDateEl.value = parts.date;
      endTimeEl.value = parts.time;
    }

    function updateIntervalFromCurrentValues() {
      const start = parseDateTime(startDateEl.value, startTimeEl.value);
      const end = parseDateTime(endDateEl.value, endTimeEl.value);
      if (start && end && end > start) {
        intervalMs = end.getTime() - start.getTime();
      }
    }

    startDateEl.addEventListener("change", syncEndFromStart);
    startTimeEl.addEventListener("change", syncEndFromStart);
    endDateEl.addEventListener("change", updateIntervalFromCurrentValues);
    endTimeEl.addEventListener("change", updateIntervalFromCurrentValues);
  }

  document.addEventListener("DOMContentLoaded", function () {
    attachDateTimeSync(
      document.querySelector('input[name="band_event_start_date"]'),
      document.querySelector('input[name="band_event_start_time"]'),
      document.querySelector('input[name="band_event_end_date"]'),
      document.querySelector('input[name="band_event_end_time"]')
    );
  });
})();
