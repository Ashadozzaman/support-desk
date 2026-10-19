// Support Desk — Jenkins CI/CD (Declarative Pipeline, Multibranch job).
//
// CI (every branch / PR): build the toolchain image → Pint + tsc in parallel → Pest against a throwaway MySQL 8.4.
// CD (main only, and only when REGISTRY / DEPLOY_HOST are set as Jenkins env vars): push app + web images,
// then deploy with docker compose over SSH after a manual approval.
//
// Agent requirements: Docker CLI + daemon access (plain `docker` via `sh`, no Docker Pipeline plugin).
// Plugins: Pipeline, Git, JUnit, Timestamper, SSH Agent (CD only). Everything PHP/Node runs inside containers, so the agent needs neither.
//
// Jenkins configuration used by CD (Manage Jenkins → System → Global properties → Environment variables):
//   REGISTRY            e.g. registry.example.com/support-desk   (image repo prefix)
//   DEPLOY_HOST         e.g. deploy@app.example.com
//   DEPLOY_PATH         e.g. /srv/support-desk                    (contains docker-compose.yml + .env.docker)
//   VITE_REVERB_HOST / VITE_REVERB_PORT / VITE_REVERB_SCHEME      (compiled into the JS bundle)
// Credentials: `docker-registry` (username/password), `reverb-app-key` (secret text), `deploy-ssh` (SSH key).

pipeline {
    agent any

    options {
        timestamps()
        timeout(time: 45, unit: 'MINUTES')
        buildDiscarder(logRotator(numToKeepStr: '20'))
        // A newer push to the same branch makes the running build pointless.
        disableConcurrentBuilds(abortPrevious: true)
    }

    stages {
        stage('Prepare') {
            steps {
                script {
                    // BUILD_TAG contains the job path ("/" for multibranch); Docker names only allow [a-z0-9_.-].
                    env.CI_ID = "sd-${env.BUILD_TAG}".toLowerCase().replaceAll('[^a-z0-9_.-]', '-')
                    env.CI_IMAGE = "support-desk-ci:${env.CI_ID}"
                    env.IMAGE_TAG = sh(script: 'git rev-parse --short=12 HEAD', returnStdout: true).trim()
                }
                // Own network per build, so parallel builds never share a MySQL container or a host port.
                sh 'docker network create "$CI_ID"'
            }
        }

        stage('Build CI image') {
            steps {
                // The `assets` stage = PHP 8.3 + extensions + composer (incl. dev) + node_modules + `npm run build`,
                // so this stage is also the frontend build check.
                sh '''
                    docker build \
                        --file docker/php/Dockerfile \
                        --target assets \
                        --build-arg VITE_REVERB_APP_KEY=ci \
                        --tag "$CI_IMAGE" \
                        .
                '''
            }
        }

        stage('Start MySQL') {
            steps {
                // Tests need real MySQL (FULLTEXT, row locks, SKIP LOCKED), not SQLite.
                sh '''
                    docker run --detach --name "$CI_ID-mysql" --network "$CI_ID" \
                        --env MYSQL_ROOT_PASSWORD=root \
                        --env MYSQL_DATABASE=support_desk_test \
                        --env TZ=UTC \
                        mysql:8.4 --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci

                    # Ping over TCP: the image's temporary init server has networking off, so this only
                    # succeeds once the real server (with the database created) is up.
                    for i in $(seq 1 60); do
                        if docker exec "$CI_ID-mysql" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent; then
                            exit 0
                        fi
                        sleep 2
                    done
                    echo "MySQL did not become ready" >&2
                    docker logs "$CI_ID-mysql" >&2
                    exit 1
                '''
            }
        }

        stage('Quality checks') {
            parallel {
                stage('Pint') {
                    steps {
                        sh 'docker run --rm "$CI_IMAGE" ./vendor/bin/pint --test'
                    }
                }
                stage('TypeScript') {
                    steps {
                        sh 'docker run --rm "$CI_IMAGE" npx tsc --noEmit'
                    }
                }
            }
        }

        stage('Publish images') {
            when {
                allOf {
                    branch 'main'
                    expression { env.REGISTRY?.trim() }
                }
            }
            steps {
                withCredentials([
                    usernamePassword(credentialsId: 'docker-registry', usernameVariable: 'REGISTRY_USER', passwordVariable: 'REGISTRY_PASSWORD'),
                    string(credentialsId: 'reverb-app-key', variable: 'VITE_REVERB_APP_KEY'),
                ]) {
                    // Single quotes: the shell expands the secrets, so Groovy never interpolates them into the log.
                    sh '''
                        echo "$REGISTRY_PASSWORD" | docker login "${REGISTRY%%/*}" --username "$REGISTRY_USER" --password-stdin

                        for target in app web; do
                            docker build \
                                --file docker/php/Dockerfile \
                                --target "$target" \
                                --build-arg VITE_REVERB_APP_KEY="$VITE_REVERB_APP_KEY" \
                                --build-arg VITE_REVERB_HOST="${VITE_REVERB_HOST:-localhost}" \
                                --build-arg VITE_REVERB_PORT="${VITE_REVERB_PORT:-443}" \
                                --build-arg VITE_REVERB_SCHEME="${VITE_REVERB_SCHEME:-https}" \
                                --tag "$REGISTRY/support-desk-$target:$IMAGE_TAG" \
                                --tag "$REGISTRY/support-desk-$target:latest" \
                                .
                            docker push "$REGISTRY/support-desk-$target:$IMAGE_TAG"
                            docker push "$REGISTRY/support-desk-$target:latest"
                        done
                    '''
                }
            }
            post {
                always {
                    sh 'docker logout "${REGISTRY%%/*}" || true'
                }
            }
        }

        stage('Deploy') {
            when {
                allOf {
                    branch 'main'
                    expression { env.REGISTRY?.trim() && env.DEPLOY_HOST?.trim() }
                }
            }
            steps {
                timeout(time: 30, unit: 'MINUTES') {
                    input message: "Deploy ${env.IMAGE_TAG} to production?", ok: 'Deploy'
                }
                sshagent(credentials: ['deploy-ssh']) {
                    // The app container runs `migrate --force` on start (RUN_MIGRATIONS=true) and the others wait
                    // for it to be healthy, so a fresh `up -d` is the whole deploy. Workers are recreated, which
                    // also does what `queue:restart` would.
                    sh '''
                        ssh -o StrictHostKeyChecking=accept-new "$DEPLOY_HOST" \
                            "cd '$DEPLOY_PATH' \
                             && export APP_IMAGE='$REGISTRY/support-desk-app:$IMAGE_TAG' WEB_IMAGE='$REGISTRY/support-desk-web:$IMAGE_TAG' \
                             && docker compose pull app nginx \
                             && docker compose up -d --no-build --remove-orphans \
                             && docker image prune -f"
                    '''
                }
            }
        }
    }

    post {
        always {
            // Throwaway containers, network and CI image; never fails the build.
            sh '''
                docker rm -f "$CI_ID-mysql" "$CI_ID-tests" >/dev/null 2>&1 || true
                docker network rm "$CI_ID" >/dev/null 2>&1 || true
                docker image rm "$CI_IMAGE" >/dev/null 2>&1 || true
            '''
        }
    }
}
