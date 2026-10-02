<?php
namespace TurboLabIt\ShopifySdk\Request;


/**
 * Reads and writes the shop's storefront URL REDIRECTS (`urlRedirects` / `urlRedirectCreate` / `urlRedirectUpdate` /
 * `urlRedirectDelete`), addressed by PATH — the natural key of a redirect (a path carries at most one). Paths and
 * targets are storefront-relative, e.g. '/products/some-handle'.
 *
 * Why a caller needs it: `productSet` / `productUpdate` with `redirectNewHandle` make Shopify 301 the OLD handle to
 * the NEW one, but only for a resource renamed IN PLACE. A URL whose resource is emptied and folded into ANOTHER
 * resource, or a 301 Shopify did not mint, has to be written explicitly.
 *
 * Contract notes:
 *   - Shopify serves a redirect only when nothing else lives at its path: a redirect sitting on a LIVE resource URL
 *     is inert but stale — it springs back to life, pointing wherever it pointed, the day that resource moves away.
 *     A caller that moves a resource onto a path should therefore delete any redirect found there.
 *   - the `path:` search filter is exact-match (no prefix/substring) and can be OR-ed; results are re-filtered
 *     client-side all the same.
 *   - reads need the `read_online_store_navigation` access scope, writes `write_online_store_navigation`: without
 *     the latter every write raises ShopifyResponseException (ACCESS_DENIED), like any other top-level error.
 *   - writes are alias-batched (several mutations per request, no bulk operation — not bulk-op eligible); per-row
 *     failures surface as formatted error strings (formatUserError idiom), prefixed with the path concerned.
 */
class ShopifyUrlRedirectRequest extends ShopifyBaseAdminRequest
{
    /** Paths OR-ed into ONE urlRedirects search (exact-match filter → at most one hit per path). */
    const int MAX_PATHS_PER_QUERY = 25;

    /** Aliased mutations per request. */
    const int MAX_WRITES_PER_CALL = 25;


    /**
     * @param string[] $arrPaths storefront-relative paths, e.g. '/products/some-handle'
     * @return array<string,\stdClass> [path => {id, path, target}] — only the paths that DO carry a redirect
     */
    public function getByPaths(array $arrPaths) : array
    {
        $arrPaths = array_values(array_unique(array_filter(array_map('strval', $arrPaths), fn(string $path) => $path !== '')));
        $arrOut   = [];

        foreach(array_chunk($arrPaths, static::MAX_PATHS_PER_QUERY) as $arrChunk) {

            $search = implode(' OR ', array_map(fn(string $path) => 'path:"' . addcslashes($path, '"\\') . '"', $arrChunk));
            $gql    = sprintf(
                '{ urlRedirects(first: %d, query: %s) { nodes { id path target } } }',
                static::MAX_PATHS_PER_QUERY, $this->literal($search)
            );

            $response  = $this->setQuery($gql, true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($oResponse->data->urlRedirects->nodes ?? [] as $oRedirect) {
                $path = (string)($oRedirect->path ?? '');
                if( in_array($path, $arrChunk, true) ) {
                    $arrOut[$path] = $oRedirect;
                }
            }
        }

        return $arrOut;
    }


    /**
     * @param array<string,string> $arrTargetByPath [path => target] — paths that carry NO redirect yet
     * @return string[] per-row error messages (empty == every redirect created)
     */
    public function createMany(array $arrTargetByPath) : array
    {
        $arrMutations = [];
        foreach($arrTargetByPath as $path => $target) {
            $arrMutations[(string)$path] = sprintf(
                'urlRedirectCreate(urlRedirect: { path: %s, target: %s }) { urlRedirect { id } userErrors { field message } }',
                $this->literal((string)$path), $this->literal((string)$target)
            );
        }

        return $this->writeMany($arrMutations);
    }


    /**
     * Re-point existing redirects (their GIDs come from getByPaths()).
     *
     * @param array $arrRedirects rows of ['id' => GID, 'path' => string, 'target' => string]
     * @return string[] per-row error messages (empty == every redirect re-pointed)
     */
    public function updateMany(array $arrRedirects) : array
    {
        $arrMutations = [];
        foreach($arrRedirects as $arrRedirect) {
            $path = (string)($arrRedirect['path'] ?? '');
            $arrMutations[$path] = sprintf(
                'urlRedirectUpdate(id: %s, urlRedirect: { path: %s, target: %s }) { urlRedirect { id } userErrors { field message } }',
                $this->literal((string)($arrRedirect['id'] ?? '')), $this->literal($path),
                $this->literal((string)($arrRedirect['target'] ?? ''))
            );
        }

        return $this->writeMany($arrMutations);
    }


    /**
     * @param array<string,string> $arrIdByPath [path => redirect GID] (getByPaths() gives both)
     * @return string[] per-row error messages (empty == every redirect deleted)
     */
    public function deleteMany(array $arrIdByPath) : array
    {
        $arrMutations = [];
        foreach($arrIdByPath as $path => $id) {
            $arrMutations[(string)$path] = sprintf(
                'urlRedirectDelete(id: %s) { deletedUrlRedirectId userErrors { field message } }',
                $this->literal((string)$id)
            );
        }

        return $this->writeMany($arrMutations);
    }


    /**
     * Run [path => mutation field] in alias-batched requests; userErrors are reported per path.
     *
     * @return string[]
     */
    protected function writeMany(array $arrMutationByPath) : array
    {
        $arrErrors = [];

        foreach(array_chunk($arrMutationByPath, static::MAX_WRITES_PER_CALL, true) as $arrChunk) {

            $arrPaths   = array_map('strval', array_keys($arrChunk));
            $arrAliases = [];
            foreach(array_values($arrChunk) as $i => $mutation) {
                $arrAliases[] = "r$i: $mutation";
            }

            $response  = $this->setQuery('mutation { ' . implode(' ', $arrAliases) . ' }', true)->connector->send($this);
            $oResponse = $this->buildFromResponse($response);

            foreach($arrPaths as $i => $path) {
                foreach($oResponse->data->{"r$i"}->userErrors ?? [] as $oError) {
                    $arrErrors[] = $path . ' → ' . $this->formatUserError($oError);
                }
            }
        }

        return $arrErrors;
    }


    /** A GraphQL string literal (JSON string escaping is GraphQL-compatible). */
    protected function literal(string $value) : string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
