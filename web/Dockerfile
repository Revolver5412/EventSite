FROM debian:buster-slim

ARG NUM_PROC=12

RUN apt update && apt install -y \
    gcc \
    libssl-dev \
    make \
    libpcre3-dev \
    zlib1g-dev \
    libxml2-dev \
    libxslt-dev \
    libgd-dev \
    libperl-dev \
    curl \
    wget \
    libmaxminddb0 \
    libmaxminddb-dev \
    mmdb-bin

WORKDIR /

##################
# DOWNLOAD NGINX #
##################
ARG NGINX_SOURCE_DIR="/nginx"
ARG NGINX_VERSION="1.18.0"
ARG NGINX_CHECKSUM="4c373e7ab5bf91d34a4f11a0c9496561061ba5eee6020db272a17a7228d35f99"
ARG NGINX_ARCHIVE_FILENAME="nginx-${NGINX_VERSION}.tar.gz"
ARG NGINX_DOWNLOAD_URL="https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz"
ARG NGINX_PREFIX="/usr/local/nginx"
RUN mkdir "${NGINX_SOURCE_DIR}"

# download
# check checksum
# extract
RUN curl --silent --location "${NGINX_DOWNLOAD_URL}" > "${NGINX_ARCHIVE_FILENAME}" \
    && echo "${NGINX_CHECKSUM}  ${NGINX_ARCHIVE_FILENAME}" | sha256sum -c - \
    && tar \
      --file "${NGINX_ARCHIVE_FILENAME}" \
      --directory "${NGINX_SOURCE_DIR}" \
      --strip 1 \
      --extract \
      --gzip


###############
# BUILD NGINX #
###############
WORKDIR "${NGINX_SOURCE_DIR}"
RUN ./configure  \
	--with-http_ssl_module \
	--with-file-aio \
	--with-threads \
    --with-cc-opt="-O3" \
    --prefix=/etc/nginx \
    --sbin-path=/usr/sbin/nginx \
    --modules-path=/usr/lib/nginx/modules \
    --conf-path=/etc/nginx/nginx.conf \
    --error-log-path=/var/log/nginx/error.log \
    --http-log-path=/var/log/nginx/access.log \
    --pid-path=/var/run/nginx.pid \
    --lock-path=/var/run/nginx.lock \
    --http-client-body-temp-path=/var/cache/nginx/client_temp \
    --http-proxy-temp-path=/var/cache/nginx/proxy_temp \
    --http-fastcgi-temp-path=/var/cache/nginx/fastcgi_temp \
    --http-uwsgi-temp-path=/var/cache/nginx/uwsgi_temp \
    --http-scgi-temp-path=/var/cache/nginx/scgi_temp \
    --with-perl_modules_path=/usr/lib/perl5/vendor_perl \
    --user=www-data \
    --group=www-data \
    --with-compat \
    --with-file-aio \
    --with-threads \
    --with-http_addition_module \
    --with-http_auth_request_module \
    --with-http_dav_module \
    --with-http_flv_module \
    --with-http_gunzip_module \
    --with-http_gzip_static_module \
    --with-http_mp4_module \
    --with-http_random_index_module \
    --with-http_realip_module \
    --with-http_secure_link_module \
    --with-http_slice_module \
    --with-http_ssl_module \
    --with-http_stub_status_module \
    --with-http_sub_module \
    --with-http_v2_module \
    --with-mail \
    --with-mail_ssl_module \
    --with-stream \
    --with-stream_realip_module \
    --with-stream_ssl_module \
    --with-stream_ssl_preread_module \
    --with-cc-opt='-Os -fomit-frame-pointer' \
    --with-ld-opt=-Wl,--as-needed && \
    make -j$NUM_PROC && \ 
    make install


# Init directories needed for nginx
RUN mkdir -p /var/cache/nginx/client_temp

#################
# INSTALL CONFD #
#################
ARG CONFD_DOWNLOAD_URL="https://github.com/kelseyhightower/confd/releases/download/v0.12.0-alpha3/confd-0.12.0-alpha3-linux-amd64"
ARG CONFD_SHA256SUM="687ff0b59f188307923abd3f08094738718641ba671e42254b250614824a0dec"
ARG CONFD_FILENAME="confd"

RUN wget --output-document "${CONFD_FILENAME}" --quiet "${CONFD_DOWNLOAD_URL}"\
 && echo "${CONFD_SHA256SUM}  ${CONFD_FILENAME}" | sha256sum -c -\
 && mv "${CONFD_FILENAME}" /usr/local/bin/confd\
 && chmod +x /usr/local/bin/confd

# copy ssl certs
RUN mkdir -p /etc/nginx/ssl/
COPY cert.crt /etc/nginx/ssl/cert.crt
COPY cert.key /etc/nginx/ssl/cert.key

####################
# COPY CONFD FILES #
####################
ADD confd/ /etc/confd
RUN mkdir -p /etc/nginx/sites-enabled /etc/nginx/sites-available

###################
# COPY ENTRYPOINT #
###################
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]

CMD ["/usr/sbin/nginx", "-g", "daemon off;"]