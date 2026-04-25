<?php

namespace App\Controllers\Api;

use App\Models\FeatureFlagUltimate;
use Core\Request;
use Core\Response;

/**
 * RESTful API برای مدیریت Feature Flags از راه دور
 * 
 * این API به شما اجازه می‌دهد:
 * - از سیستم‌های Third-party مدیریت کنید
 * - Automation ایجاد کنید
 * - Integration با CI/CD
 */
class FeatureFlagApiController
{
    private FeatureFlagUltimate $model;
    private Request $request;
    private Response $response;
    
    public function __construct(
        FeatureFlagUltimate $model,
        Request $request,
        Response $response
    ) {
        $this->model = $model;
        $this->request = $request;
        $this->response = $response;
        
        // API Authentication check
        $this->authenticate();
    }
    
    /**
     * Authentication برای API
     */
    private function authenticate(): void
    {
        $apiKey = $this->request->header('X-API-Key');
        
        if (!$apiKey) {
            $this->response->json([
                'error' => 'Missing API key',
                'message' => 'Please provide X-API-Key header'
            ], 401)->send();
            exit;
        }
        
        // Check API key validity
        $validKey = env('FEATURE_FLAG_API_KEY');
        
        if (!$validKey || !hash_equals($validKey, $apiKey)) {
            $this->response->json([
                'error' => 'Invalid API key',
                'message' => 'The provided API key is invalid'
            ], 403)->send();
            exit;
        }
    }
    
    /**
     * GET /api/v1/features
     * لیست تمام فیچرها
     */
    public function index()
    {
        try {
            $features = $this->model->getAll();
            
            // فیلتر کردن فیلدهای حساس اگر نیاز باشد
            $public = array_map(function($feature) {
                return [
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'enabled' => (bool)$feature->enabled,
                    'enabled_percentage' => (int)$feature->enabled_percentage,
                    'priority' => (int)($feature->priority ?? 0),
                    'tags' => $feature->tags ? json_decode($feature->tags) : [],
                    'created_at' => $feature->created_at,
                    'updated_at' => $feature->updated_at,
                ];
            }, $features);
            
            return $this->response->json([
                'success' => true,
                'data' => $public,
                'meta' => [
                    'total' => count($public),
                    'timestamp' => date('Y-m-d H:i:s'),
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * GET /api/v1/features/{name}
     * دریافت اطلاعات یک فیچر
     */
    public function show(string $name)
    {
        try {
            $feature = $this->model->findByName($name);
            
            if (!$feature) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Feature not found',
                    'message' => "Feature '{$name}' does not exist",
                ], 404);
            }
            
            return $this->response->json([
                'success' => true,
                'data' => [
                    'name' => $feature->name,
                    'description' => $feature->description,
                    'enabled' => (bool)$feature->enabled,
                    'enabled_percentage' => (int)$feature->enabled_percentage,
                    'enabled_for_roles' => $feature->enabled_for_roles 
                        ? json_decode($feature->enabled_for_roles) 
                        : null,
                    'enabled_for_users' => $feature->enabled_for_users 
                        ? json_decode($feature->enabled_for_users) 
                        : null,
                    'enabled_from' => $feature->enabled_from,
                    'enabled_until' => $feature->enabled_until,
                    'depends_on' => $feature->depends_on 
                        ? json_decode($feature->depends_on) 
                        : null,
                    'environments' => $feature->environments 
                        ? json_decode($feature->environments) 
                        : null,
                    'priority' => (int)($feature->priority ?? 0),
                    'tags' => $feature->tags ? json_decode($feature->tags) : [],
                    'metadata' => $feature->metadata ? json_decode($feature->metadata) : null,
                    'created_at' => $feature->created_at,
                    'updated_at' => $feature->updated_at,
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * POST /api/v1/features/{name}/check
     * بررسی فعال بودن فیچر برای یک کاربر
     */
    public function check(string $name)
    {
        try {
            $data = $this->request->json();
            $userId = $data['user_id'] ?? null;
            
            $enabled = $this->model->isEnabled($name, $userId);
            
            return $this->response->json([
                'success' => true,
                'data' => [
                    'feature' => $name,
                    'enabled' => $enabled,
                    'user_id' => $userId,
                    'checked_at' => date('Y-m-d H:i:s'),
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * POST /api/v1/features
     * ایجاد فیچر جدید
     */
    public function create()
    {
        try {
            $data = $this->request->json();
            
            // Validation
            if (empty($data['name']) || empty($data['description'])) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Validation error',
                    'message' => 'name and description are required',
                ], 400);
            }
            
            $this->model->create($data);
            
            return $this->response->json([
                'success' => true,
                'message' => 'Feature created successfully',
                'data' => [
                    'name' => $data['name'],
                ],
            ], 201);
            
        } catch (\InvalidArgumentException $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Validation error',
                'message' => $e->getMessage(),
            ], 400);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * PATCH /api/v1/features/{name}
     * به‌روزرسانی فیچر
     */
    public function update(string $name)
    {
        try {
            $data = $this->request->json();
            
            $this->model->update($name, $data);
            
            return $this->response->json([
                'success' => true,
                'message' => 'Feature updated successfully',
                'data' => [
                    'name' => $name,
                ],
            ]);
            
        } catch (\InvalidArgumentException $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Validation error',
                'message' => $e->getMessage(),
            ], 400);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * POST /api/v1/features/{name}/toggle
     * تغییر وضعیت فیچر
     */
    public function toggle(string $name)
    {
        try {
            $result = $this->model->toggle($name);
            
            if (!$result) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Feature not found',
                    'message' => "Feature '{$name}' does not exist",
                ], 404);
            }
            
            $feature = $this->model->findByName($name);
            
            return $this->response->json([
                'success' => true,
                'message' => 'Feature toggled successfully',
                'data' => [
                    'name' => $name,
                    'enabled' => (bool)$feature->enabled,
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * POST /api/v1/features/{name}/rollout
     * تنظیم درصد Rollout
     */
    public function rollout(string $name)
    {
        try {
            $data = $this->request->json();
            
            if (!isset($data['percentage'])) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Validation error',
                    'message' => 'percentage is required',
                ], 400);
            }
            
            $percentage = (int)$data['percentage'];
            
            if ($percentage < 0 || $percentage > 100) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Validation error',
                    'message' => 'percentage must be between 0 and 100',
                ], 400);
            }
            
            $this->model->update($name, ['enabled_percentage' => $percentage]);
            
            return $this->response->json([
                'success' => true,
                'message' => 'Rollout percentage updated successfully',
                'data' => [
                    'name' => $name,
                    'percentage' => $percentage,
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * DELETE /api/v1/features/{name}
     * حذف فیچر
     */
    public function delete(string $name)
    {
        try {
            $result = $this->model->delete($name);
            
            if (!$result) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Feature not found',
                    'message' => "Feature '{$name}' does not exist",
                ], 404);
            }
            
            return $this->response->json([
                'success' => true,
                'message' => 'Feature deleted successfully',
                'data' => [
                    'name' => $name,
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * GET /api/v1/features/{name}/stats
     * دریافت آمار یک فیچر
     */
    public function stats(string $name)
    {
        try {
            $feature = $this->model->findByName($name);
            
            if (!$feature) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Feature not found',
                ], 404);
            }
            
            $metrics = $this->model->getMetrics($name, 24);
            $history = $this->model->getHistory($name, 10);
            
            return $this->response->json([
                'success' => true,
                'data' => [
                    'feature' => $name,
                    'enabled' => (bool)$feature->enabled,
                    'metrics_24h' => $metrics,
                    'recent_changes' => $history,
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * GET /api/v1/stats
     * آمار کلی سیستم
     */
    public function systemStats()
    {
        try {
            $stats = $this->model->getStats();
            
            return $this->response->json([
                'success' => true,
                'data' => $stats,
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    
    /**
     * POST /api/v1/features/bulk-check
     * بررسی چندین فیچر به صورت یکجا
     */
    public function bulkCheck()
    {
        try {
            $data = $this->request->json();
            
            if (empty($data['features']) || !is_array($data['features'])) {
                return $this->response->json([
                    'success' => false,
                    'error' => 'Validation error',
                    'message' => 'features array is required',
                ], 400);
            }
            
            $userId = $data['user_id'] ?? null;
            $results = [];
            
            foreach ($data['features'] as $featureName) {
                $results[$featureName] = $this->model->isEnabled($featureName, $userId);
            }
            
            return $this->response->json([
                'success' => true,
                'data' => [
                    'user_id' => $userId,
                    'features' => $results,
                    'checked_at' => date('Y-m-d H:i:s'),
                ],
            ]);
            
        } catch (\Exception $e) {
            return $this->response->json([
                'success' => false,
                'error' => 'Internal server error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
