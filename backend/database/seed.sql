-- Demo missions (configuration, not health data). Safe to run more than once.
INSERT IGNORE INTO missions (code, name, status, description) VALUES
    ('MARS-EXP', 'Mars Expedition', 'active', 'Long-duration Mars transit crew (demo mission).'),
    ('LUNAR-GW', 'Lunar Gateway', 'planning', 'Lunar Gateway orbital outpost crew (demo mission).');
