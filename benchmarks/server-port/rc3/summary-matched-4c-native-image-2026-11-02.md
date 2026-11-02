| Runtime / window | Accepted | Verified | Pending | In-window | p50 s | p95 s | p99 s | Runtime core-s/wf | MySQL core-s/wf | Runtime max MiB | Health fail/replies |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| native-image / setup | 2 | 2 | 0 | 2 | 0.117 | 0.186 | 0.186 | 0.125 | 0.268 | 5.0 | 0/8 |
| native-image / warmup | 15 | 15 | 0 | 15 | 0.105 | 0.179 | 0.179 | 0.156 | 0.308 | 6.0 | 0/108 |
| native-image / low-before-1 | 30 | 30 | 0 | 30 | 0.104 | 0.117 | 0.124 | 0.157 | 0.309 | 7.2 | 0/216 |
| native-image / low-before-2 | 30 | 30 | 0 | 30 | 0.108 | 0.121 | 0.122 | 0.160 | 0.315 | 7.2 | 0/214 |
| native-image / low-before-3 | 30 | 30 | 0 | 30 | 0.108 | 0.129 | 0.161 | 0.160 | 0.317 | 7.3 | 0/216 |
| native-image / rate-0.5-1 | 60 | 60 | 0 | 60 | 0.106 | 0.125 | 0.203 | 0.107 | 0.217 | 6.5 | 0/218 |
| native-image / rate-0.5-2 | 60 | 60 | 0 | 60 | 0.104 | 0.114 | 0.161 | 0.106 | 0.216 | 7.3 | 0/216 |
| native-image / rate-0.5-3 | 60 | 60 | 0 | 60 | 0.106 | 0.120 | 0.142 | 0.107 | 0.219 | 6.7 | 0/214 |
| native-image / rate-1-1 | 120 | 120 | 0 | 120 | 0.103 | 0.119 | 0.136 | 0.077 | 0.161 | 6.7 | 0/216 |
| native-image / rate-1-2 | 120 | 120 | 0 | 120 | 0.104 | 0.120 | 0.127 | 0.080 | 0.167 | 7.6 | 0/218 |
| native-image / rate-1-3 | 120 | 120 | 0 | 120 | 0.104 | 0.115 | 0.120 | 0.082 | 0.168 | 7.1 | 0/216 |
| native-image / low-after-1 | 30 | 30 | 0 | 30 | 0.107 | 0.122 | 0.137 | 0.159 | 0.326 | 6.8 | 0/216 |
| native-image / low-after-2 | 30 | 30 | 0 | 30 | 0.105 | 0.129 | 0.184 | 0.160 | 0.327 | 7.3 | 0/214 |
| native-image / low-after-3 | 30 | 30 | 0 | 30 | 0.105 | 0.119 | 0.122 | 0.156 | 0.322 | 6.6 | 0/216 |

| Runtime / idle | Polls | Outcomes | Runtime core-s | Health fail/replies, slowest ok s |
| --- | ---: | --- | ---: | --- |
| native-image / idle-6-1 | 6 | {"empty": 6} | 0.395 | 0/18, 0.097 |
| native-image / idle-6-2 | 6 | {"empty": 6} | 0.443 | 0/18, 0.007 |
| native-image / idle-6-3 | 6 | {"empty": 6} | 0.424 | 0/20, 0.091 |
| native-image / idle-12-1 | 12 | {"empty": 12} | 0.592 | 0/18, 0.009 |
| native-image / idle-12-2 | 12 | {"empty": 12} | 0.561 | 0/20, 0.009 |
| native-image / idle-12-3 | 12 | {"empty": 12} | 0.6 | 0/18, 0.101 |
| native-image / idle-24-1 | 24 | {"empty": 24} | 0.862 | 0/20, 0.008 |
| native-image / idle-24-2 | 24 | {"empty": 24} | 0.827 | 0/20, 0.01 |
| native-image / idle-24-3 | 24 | {"empty": 24} | 0.947 | 0/20, 0.012 |
