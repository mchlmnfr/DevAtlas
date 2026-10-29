/**
 * Filename: dashboard.js
 * Purpose: Display a localized dashboard greeting using the existing employee name.
 * Developer: DevAtlas maintainers
 * Date: 2026-10-29
 * Recent updates: Move dashboard presentation logic out of the view template.
 */
(() =>
{
    const GREETING = document.getElementById('greetingText');
    if (!GREETING)
    {
        return;
    }
    function Greet()
    {
        const NOW = new Date();
        const HOUR = Number(new Intl.DateTimeFormat('en-PH',
        {
            timeZone: 'Asia/Manila',
            hour: 'numeric',
            hourCycle: 'h23'
        }).format(NOW));
        GREETING.textContent = (HOUR < 12 ? 'Good morning' : HOUR < 18 ? 'Good afternoon' : 'Good evening') + ', ' + GREETING.dataset.greetingName;
        document.getElementById('greetingDate').textContent = NOW.toLocaleDateString('en-PH',
        {
            timeZone: 'Asia/Manila',
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
    }
    Greet();
    setInterval(Greet, 60000);
})();
