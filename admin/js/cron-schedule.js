(function () {
    'use strict';

    var monthNames = {
        1: 'January',
        2: 'February',
        3: 'March',
        4: 'April',
        5: 'May',
        6: 'June',
        7: 'July',
        8: 'August',
        9: 'September',
        10: 'October',
        11: 'November',
        12: 'December'
    };

    var weekdayNames = {
        0: 'Sunday',
        1: 'Monday',
        2: 'Tuesday',
        3: 'Wednesday',
        4: 'Thursday',
        5: 'Friday',
        6: 'Saturday',
        7: 'Sunday'
    };

    function isDigit(value) {
        return /^\d+$/.test(value);
    }

    function fieldValue(value, names) {
        return names[value] || String(value);
    }

    function joinWords(values) {
        values = values.filter(function (value) {
            return value !== '';
        });

        if (values.length <= 1) {
            return values[0] || '';
        }

        var last = values.pop();
        return values.join(', ') + ' and ' + last;
    }

    function describeField(field, names) {
        var match;

        if (field === '*') {
            return 'every';
        }

        match = field.match(/^\*\/(\d+)$/);
        if (match) {
            return 'every ' + parseInt(match[1], 10);
        }

        match = field.match(/^(\d+)-(\d+)$/);
        if (match) {
            return fieldValue(parseInt(match[1], 10), names || {}) + ' through ' + fieldValue(parseInt(match[2], 10), names || {});
        }

        if (field.indexOf(',') !== -1) {
            return joinWords(field.split(',').map(function (value) {
                return isDigit(value) ? fieldValue(parseInt(value, 10), names || {}) : value;
            }));
        }

        return isDigit(field) ? fieldValue(parseInt(field, 10), names || {}) : field;
    }

    function describeHourWindow(hour) {
        var match = hour.match(/^(\d+)-(\d+)$/);

        if (match) {
            return 'from ' + parseInt(match[1], 10) + ' through ' + parseInt(match[2], 10);
        }

        if (hour.indexOf(',') !== -1) {
            return 'at hours ' + describeField(hour);
        }

        match = hour.match(/^\*\/(\d+)$/);
        if (match) {
            return 'every ' + parseInt(match[1], 10) + ' hours';
        }

        if (isDigit(hour)) {
            return 'during hour ' + parseInt(hour, 10);
        }

        return 'when hour matches ' + hour;
    }

    function describeTimeFields(minute, hour) {
        var sentence;

        if (minute === '*') {
            if (hour === '*') {
                return 'every minute';
            }

            return 'every minute ' + describeHourWindow(hour);
        }

        if (isDigit(minute) && isDigit(hour)) {
            return 'at ' + String(parseInt(hour, 10)).padStart(2, '0') + ':' + String(parseInt(minute, 10)).padStart(2, '0');
        }

        sentence = 'at minute ' + describeField(minute) + ' past every hour';
        if (hour !== '*') {
            sentence += ' ' + describeHourWindow(hour);
        }

        return sentence;
    }

    function describeSchedule(schedule) {
        var aliases = {
            '@hourly': '0 * * * *',
            '@daily': '15 8 * * *',
            '@weekly': '15 8 * * 1',
            '@monthly': '15 8 1 * *'
        };
        var parts;
        var minute;
        var hour;
        var day;
        var month;
        var weekday;
        var sentence;
        var details = [];
        var stepMatch;

        schedule = String(schedule || '').trim().replace(/\s+/g, ' ');
        schedule = aliases[schedule] || schedule;
        parts = schedule.split(' ');

        if (parts.length !== 5) {
            return 'Invalid cron expression.';
        }

        minute = parts[0];
        hour = parts[1];
        day = parts[2];
        month = parts[3];
        weekday = parts[4];

        if (minute === '*' && hour === '*' && day === '*' && month === '*' && weekday === '*') {
            return 'Every minute.';
        }

        stepMatch = minute.match(/^\*\/(\d+)$/);
        if (stepMatch && hour === '*' && day === '*' && month === '*' && weekday === '*') {
            return 'Every ' + parseInt(stepMatch[1], 10) + ' minutes.';
        }

        sentence = describeTimeFields(minute, hour);

        if (day !== '*') {
            details.push('on day ' + describeField(day) + ' of the month');
        }

        if (month !== '*') {
            details.push('in ' + describeField(month, monthNames));
        }

        if (weekday !== '*') {
            details.push('on ' + describeField(weekday, weekdayNames));
        }

        if (!details.length && /^at \d{2}:\d{2}$/.test(sentence)) {
            sentence += ' every day';
        }

        if (details.length) {
            sentence += ' ' + details.join(' ');
        }

        return sentence.charAt(0).toUpperCase() + sentence.slice(1) + '.';
    }

    function initNewCronDescription() {
        var input = document.getElementById('new_cron_schedule');
        var output = document.getElementById('new_cron_schedule_description');

        if (!input || !output) {
            return;
        }

        function updateDescription() {
            output.textContent = describeSchedule(input.value);
        }

        input.addEventListener('input', updateDescription);
        updateDescription();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNewCronDescription);
    } else {
        initNewCronDescription();
    }
}());
