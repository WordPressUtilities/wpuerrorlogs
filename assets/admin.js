document.addEventListener("DOMContentLoaded", function() {
    'use strict';

    function draw_chart(id, labels, values) {
        var $wrapper = document.getElementById(id);
        if (!$wrapper) {
            return;
        }
        new Chart($wrapper.getContext('2d'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Errors',
                    data: values,
                    backgroundColor: 'rgba(255, 99, 132, 0.2)',
                    borderColor: 'rgba(255, 99, 132, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }

    if (typeof wpuerrorlogs_errors_by_day !== 'undefined') {
        draw_chart('wpuerrorlogs_errors_by_day', Object.keys(wpuerrorlogs_errors_by_day), Object.values(wpuerrorlogs_errors_by_day));
    }

    if (typeof wpuerrorlogs_errors_by_hour !== 'undefined') {
        draw_chart('wpuerrorlogs_errors_by_hour', Object.keys(wpuerrorlogs_errors_by_hour).map(function(label) {
            return label + 'h';
        }), Object.values(wpuerrorlogs_errors_by_hour));
    }

    if (typeof wpuerrorlogs_errors_by_day_hour !== 'undefined') {
        draw_chart('wpuerrorlogs_errors_by_day_hour', Object.keys(wpuerrorlogs_errors_by_day_hour), Object.values(wpuerrorlogs_errors_by_day_hour));
    }
});
