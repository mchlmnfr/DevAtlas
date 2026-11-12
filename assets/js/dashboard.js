/**
 * Filename: dashboard.js
 * Purpose: Present the dashboard greeting and account-owned widget customization.
 * Developer: DevAtlas maintainers
 * Date: 2026-11-12
 * Recent updates: Preview and persist widget order, visibility, spacing and interface motion.
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


