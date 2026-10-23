//! What published PHP 2.11.1 answers for a request no native route serves.
//! PHP's route table (`php-routes.v2.11.1.json`, from `route:list`) decides:
//! a path PHP serves with this method is a PHP capability native does not
//! have yet, refused with 501; a path PHP serves with other methods is PHP's
//! 405, naming them; any other path is PHP's 404.
use serde_json::Value;
use std::sync::OnceLock;

struct Route {
    methods: Vec<String>,
    segments: Vec<String>,
    /// A parameter that takes the rest of the path, as `->where('name', '.+')`.
    wide: Option<String>,
}

fn routes() -> &'static [Route] {
    static ROUTES: OnceLock<Vec<Route>> = OnceLock::new();
    ROUTES.get_or_init(|| {
        let table: Value = serde_json::from_str(include_str!("php-routes.v2.11.1.json")).expect("PHP route table");
        let wide = &table["wide_parameters"];
        table["routes"]
            .as_array()
            .expect("routes")
            .iter()
            .map(|route| {
                let uri = route[1].as_str().expect("uri").trim_matches('/');
                let segments: Vec<String> =
                    if uri.is_empty() { Vec::new() } else { uri.split('/').map(str::to_owned).collect() };
                let wide = wide.as_object().and_then(|prefixes| {
                    prefixes
                        .iter()
                        .find(|(prefix, _)| uri.starts_with(prefix.as_str()))
                        .and_then(|(_, name)| name.as_str().map(str::to_owned))
                });
                Route {
                    methods: route[0].as_str().expect("methods").split('|').map(str::to_owned).collect(),
                    segments,
                    wide,
                }
            })
            .collect()
    })
}

/// Whether the route's pattern matches the path, a `{parameter}` taking one
/// non-empty segment, or the rest of the path when it is the wide one.
fn matches(route: &Route, path: &[&str]) -> bool {
    let mut at = 0;
    for (index, segment) in route.segments.iter().enumerate() {
        let parameter = segment.starts_with('{') && segment.ends_with('}');
        if parameter && route.wide.as_deref() == Some(&segment[1..segment.len() - 1]) {
            // The wide parameter takes what the rest of the pattern leaves.
            let rest = route.segments.len() - index - 1;
            let Some(end) = path.len().checked_sub(rest).filter(|end| *end > at) else {
                return false;
            };
            if path[at..end].iter().any(|part| part.is_empty()) {
                return false;
            }
            at = end;
            continue;
        }
        let Some(part) = path.get(at) else {
            return false;
        };
        let matched = if parameter { !part.is_empty() } else { part == segment };
        if !matched {
            return false;
        }
        at += 1;
    }
    at == path.len()
}

#[derive(Debug, PartialEq)]
pub(super) enum Answer {
    /// PHP serves this request; native does not yet.
    NotImplemented,
    MethodNotAllowed(String),
    NotFound(String),
}

pub(super) fn answer(method: &str, path: &str) -> Answer {
    let trimmed = path.trim_matches('/');
    let parts: Vec<&str> = if trimmed.is_empty() { Vec::new() } else { trimmed.split('/').collect() };
    let shown = if trimmed.is_empty() { "/" } else { trimmed };
    let mut allowed: Vec<&str> = Vec::new();
    for route in routes().iter().filter(|route| matches(route, &parts)) {
        if route.methods.iter().any(|allowed| allowed == method) {
            return Answer::NotImplemented;
        }
        for method in &route.methods {
            if !allowed.contains(&method.as_str()) {
                allowed.push(method);
            }
        }
    }
    if allowed.is_empty() {
        Answer::NotFound(format!("The route {shown} could not be found."))
    } else {
        Answer::MethodNotAllowed(format!(
            "The {method} method is not supported for route {shown}. Supported methods: {}.",
            allowed.join(", ")
        ))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn answers_follow_php_s_route_table() {
        assert_eq!(answer("GET", "/api/nope"), Answer::NotFound("The route api/nope could not be found.".into()));
        assert_eq!(answer("POST", "/api/workflows/x/redrive"), Answer::NotFound("The route api/workflows/x/redrive could not be found.".into()));
        assert_eq!(answer("DELETE", "/api/workflows"),
            Answer::MethodNotAllowed("The DELETE method is not supported for route api/workflows. Supported methods: GET, HEAD, POST.".into()));
        assert_eq!(answer("POST", "/api/deployments"),
            Answer::MethodNotAllowed("The POST method is not supported for route api/deployments. Supported methods: GET, HEAD.".into()));
        assert_eq!(answer("PUT", "/api/workflows/x/signal/s"),
            Answer::MethodNotAllowed("The PUT method is not supported for route api/workflows/x/signal/s. Supported methods: POST.".into()));
        // The deployment name takes slashes; an action segment still ends it.
        assert_eq!(answer("GET", "/api/deployments/default/q@v1"), Answer::NotImplemented);
        assert_eq!(answer("POST", "/api/deployments/default/q@v1/promote"), Answer::NotImplemented);
        assert_eq!(answer("HEAD", "/api/workflows"), Answer::NotImplemented);
        assert_eq!(answer("GET", "/"), Answer::NotImplemented);
    }
}
