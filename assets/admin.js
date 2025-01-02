document.addEventListener("DOMContentLoaded", function() {
    'use strict';

    var $wrapper = document.getElementById('wpuerrorlogs_errors_by_hour');
    if (!$wrapper) {
        return;
    }

    var ctx = $wrapper.getContext('2d');
    var _labels = Object.keys(wpuerrorlogs_errors_by_hour).map(function(label) {
        return label + 'h';
    });

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: _labels,
            datasets: [{
                label: 'Errors',
                data: Object.values(wpuerrorlogs_errors_by_hour),
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
});
