<?php

namespace Api\Models\Traits;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Config;

use Carbon\Carbon;

/**
 * Add ability to audit to the cloud - such as s3
 * Enable revision support on s3
 */
trait CloudAuditable
{
    protected $no_audit = false;

    public static function bootCloudAuditable()
    {
        static::created(function ($auditable) {
            $auditable->cloudAudit('create');
        });

        static::updated(function ($auditable) {
            $changes = $auditable->getDirty();
            $changed = [];
            foreach ($changes as $key => $value) {
                $record = [
                    'key' => $key,
                    'old' => $auditable->getOriginal($key),
                    'new' => $auditable->$key
                ];

                // do not log sensitive data
                if (array_search($key, $auditable->hidden)) {
                    $record['old'] = '***HIDDEN***';
                    $record['new'] = '***HIDDEN***';
                }

                $changed[] = $record;
            }

            $auditable->cloudAudit('update', $changed);
        });

        static::deleted(function ($auditable) {
            $auditable->cloudAudit('delete');
        });
    }

    public function canCloudAudit()
    {
        $bucket = config('admin.auditable.bucket');
        if (!isset($bucket) || strlen($bucket) <= 0) {
            return false;
        }

        if ($this->no_audit) {
            return false;
        }

        $iten = config('admin.include.tenant');
        $itab = config('admin.include.table');
        if (!isset($iten) || !isset($itab)) {
            return false;
        }

        $tn    = $this->getTable();
        $parts = explode($tn, ",");
        if (!preg_match($iten, $parts[0]) || preg_match($itab, $parts[1])) {
            return false;
        }

        $xten = config('admin.exclude.tenant');
        $xtab = config('admin.exclude.table');

        if (isset($xten) && preg_match($xten, $parts[0])) {
            return false;
        }

        if (isset($xtab) && preg_match($xtab, $parts[1])) {
            return false;
        }

        return true;
    }

    public function cloudAudit($action, $log = [])
    {
        $id  = $this->id;
        $uid = $this->uid;
        if (!isset($id) || !isset($uid)) {
            return;
        }

        if (!$this->canCloudAudit()) {
            return;
        }

        $model        = $this->toArray();
        $request      = request();
        $user         = null;
        $now          = Carbon::now('UTC');
        $request_info = [];
        if (isset($request)) {
            $user         = $request->user();
            $route_params = $request->route()->parameters();
            $memory_usage = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
            $request_info = [
                'user'          => $user,
                'id_address'    => $request->ip(),
                'user_agent'    => $request->userAgent(),
                'referer'       => $request->header('referer'),
                'locale'        => $request->header('accept-language'),
                'forwarded_for' => $request->header('x-forwarded-for'),
                'content_type'  => $request->header('content-type'),
                'content_length'=> $request->header('content-length'),
                'device_type'   => $request->device_type,
                'os'            => $request->os,
                'browser'       => $request->browser,
                'client'        => $request->client_information,
                'host'          => $request->getHttpHost(),
                'method'        => $request->method(),
                'path'          => $request->path(),
                'url'           => $request->url(),
                'full_url'      => $request->fullUrl(),
                'route_name'    => $route_name,
                'route_action'  => $request->route()->getActionName(),
                'route_query'   => $request->query(),
                'uname'         => php_uname(),
                'route_params'  => $route_params,
                'mem_use'       => $memory_usage
            ];
        }

        $info = $this->getCloudAuditInfo() ?: [];
        $body = array_merge($info, [
            // unique id allow for event idempotency/nonce key
            'uid'          => $this->uid,
            'app_name'     => config('app.name'),
            'class_name'   => get_class($this),
            'table_name'   => $tn,
            'tenant'       => $parts[0],
            'table'        => $parts[1],
            'action'       => $action,
            'user_id'      => isset($user) ? $user->id : null,
            'user_request' => $request_info,
            'log'          => $log,
            'model_id'     => $id,
            'model'        => $model,
            'created_at'   => $now->timestamp
        ]);
        $path = $this->getCloudAuditFile($body);

        return $this->cloudAuditWrite("index.json", $body);
    }

    /**
     * override this function to provide extra audit data
     * @return Array  Audit info
     */
    public function getCloudAuditInfo()
    {
        return [];
    }

    /**
     * write to cloud - allow to override or special audit
     * per example use in bulk import
     *
     * @param  string $body     the object
     * @param  string $filename the file name without extension, null is $timestamp-log.json
     * @return object           the current object
     */
    public function cloudAuditWrite($body, $filename = null)
    {
        if (!isset($filename)) {
            // timestamp in reverse chronological order
            // this allow for latest first
            $now      = Carbon::now('UTC');
            $filename = (9999 - $now->year) .
                (99 - $now->month) .
                (99 - $now->day) .
                "-tsrev";
        }

        $fn    = \Str::slug($filename) . ".json";
        $table = $this->getTable();
        $uid   = $this->uid;
        $path  = "uid/$table/$fn";

        // store to s3
        \Storage::disk('s3')
            ->getDriver()
            ->getAdapter()
            ->getClient()
            ->upload(
                $bucket,
                $path,
                gzencode(json_encode($body)),
                'private',
                ['params' => [
                        'ContentType' => 'application/json',
                        'ContentEncoding' => 'gzip',
                    ]
                ]
            );

        return $this;
    }

    public function getNoAuditAttribute()
    {
        return $this->no_audit;
    }

    public function setNoAuditAttribute($value)
    {
        $this->no_audit = $value;
    }
}
